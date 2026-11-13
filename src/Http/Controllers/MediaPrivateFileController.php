<?php

namespace Dashed\DashedFiles\Http\Controllers;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Symfony\Component\HttpFoundation\Response;
use Dashed\DashedFiles\Classes\Access\MediaRights;

/**
 * Opent een privé-bestand in het beheer. De link is ondertekend en kort
 * geldig, en werkt alleen voor een ingelogde paneelgebruiker met het recht
 * `view_media` (de route staat achter de middleware van het paneel: MFA en
 * de IP-lijst gelden dus ook).
 *
 * Het verzoek draagt geen pad: alleen het id van het item en een naam uit
 * een vaste lijst. Waar het bestand staat komt uit de database.
 */
class MediaPrivateFileController
{
    public const ROUTE = 'media.private';

    /** @var array<int, string> wat er op te vragen valt */
    public const VARIANTS = ['original', 'thumb', 'admin-thumb', 'medium', 'preview', 'source'];

    // Typen die een browser als pagina of script kan uitvoeren: altijd als download.
    private const ACTIVE_TYPES = ['image/svg+xml', 'text/html', 'application/xhtml+xml', 'text/xml', 'application/xml', 'text/javascript', 'application/javascript', 'text/css'];

    // Wat een browser veilig zelf kan tonen.
    private const INLINE_PREFIXES = ['image/', 'video/', 'audio/'];

    public function __invoke(Request $request, int|string $item, string $variant = 'original'): Response
    {
        abort_unless($request->hasValidSignature(), 403);
        abort_unless(MediaLibrary::usesOwnEngine() && MediaRights::check('view'), 403);
        abort_unless(in_array($variant, self::VARIANTS, true), 404);
        abort_unless(is_numeric($item) && (int) $item > 0, 404);

        $model = MediaItem::withTrashed()->with('media')->find((int) $item);
        $file = $model ? app(MediaLibrary::class)->privateFile($model, $variant) : null;

        abort_unless($file !== null, 404);

        // Voorwaardelijk: wie het bestand al heeft en de ETag of datum meestuurt
        // krijgt een 304, zonder dat het bestand van de schijf gelezen wordt.
        // Pas na de handtekening en het recht hierboven, nooit ervoor.
        $cache = new Response('', 200, ['Cache-Control' => $this->cacheControl($request), 'X-Robots-Tag' => 'noindex']);
        $cache->setEtag((string) ($file['etag'] ?? md5($file['disk'] . '|' . $file['path'])));

        if (! empty($file['modified'])) {
            $cache->setLastModified(\DateTimeImmutable::createFromFormat('U', (string) $file['modified']) ?: null);
        }

        if ($cache->isNotModified($request)) {
            return $cache;
        }

        $disk = Storage::disk($file['disk']);
        $size = $file['size'] ?? rescue(fn () => (int) $disk->size($file['path']), null, false);
        $range = $this->range($request, $size);

        // Een bereik dat niet te lezen of niet te leveren is.
        if ($range === false) {
            abort(response('', 416, array_filter(['Content-Range' => $size !== null ? "bytes */{$size}" : null, 'Accept-Ranges' => 'bytes'])));
        }

        $stream = null;

        if ($range !== null) {
            $stream = $this->openAt($disk, $file['path'], $range[0], $range[1]);

            // De schijf kan niet vanaf een plek lezen: dan het hele bestand.
            if ($stream === null) {
                $range = null;
            }
        }

        if ($stream === null) {
            try {
                $stream = $disk->readStream($file['path']);
            } catch (Throwable) {
                $stream = null;
            }
        }

        abort_unless(is_resource($stream), 404);

        $mime = strtolower($file['mime']);
        $active = in_array($mime, self::ACTIVE_TYPES, true) || str_contains($mime, 'html') || str_contains($mime, 'script');
        $inline = ! $active && ! $request->boolean('download')
            && ($mime === 'application/pdf' || collect(self::INLINE_PREFIXES)->contains(fn (string $prefix) => str_starts_with($mime, $prefix)));

        $headers = [
            'Content-Type' => $mime !== '' ? $mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $this->cacheControl($request),
            'ETag' => $cache->headers->get('ETag'),
            'X-Robots-Tag' => 'noindex',
        ];

        if ($cache->headers->has('Last-Modified')) {
            $headers['Last-Modified'] = $cache->headers->get('Last-Modified');
        }

        // Ook als een browser het toch opent: geen script, geen formulier,
        // geen eigen herkomst. Niet voor een pdf die getoond wordt: de
        // pdf-weergave van een browser werkt niet in een zandbak.
        if (! ($inline && $mime === 'application/pdf')) {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:";
        }

        if ($size !== null) {
            $headers['Accept-Ranges'] = 'bytes';
            $headers['Content-Length'] = (string) ($range !== null ? $range[1] - $range[0] + 1 : $size);
        }

        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$range[0]}-{$range[1]}/{$size}";
        }

        $length = $range !== null ? $range[1] - $range[0] + 1 : null;

        $response = response()->stream(function () use ($stream, $length) {
            try {
                if ($length === null) {
                    fpassthru($stream);

                    return;
                }

                // In blokken, nooit het hele bereik in het geheugen.
                $left = $length;

                while ($left > 0 && ! feof($stream)) {
                    $chunk = fread($stream, (int) min(8192, $left));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $left -= strlen($chunk);
                    echo $chunk;
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, $range !== null ? 206 : 200, $headers);

        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            $inline ? 'inline' : 'attachment',
            $file['name'],
            preg_replace('/[^A-Za-z0-9._-]+/', '-', $file['name']) ?: 'bestand',
        ));

        return $response;
    }

    /**
     * Nooit `public`: dit bestand mag alleen naar wie de handtekening en het
     * recht heeft, dus geen gedeelde cache of CDN. Een browser mag het wel
     * houden zolang de handtekening van deze link geldig is: daarna had hij
     * het bestand toch niet meer kunnen opvragen.
     */
    private function cacheControl(Request $request): string
    {
        $left = max(0, (int) $request->query('expires', 0) - time());

        return $left > 0 ? "private, max-age={$left}" : 'private, no-cache';
    }

    /**
     * Het gevraagde bereik: één `bytes=a-b`, `bytes=a-` of `bytes=-n`.
     *
     * @return array{0: int, 1: int}|false|null null zonder Range (of met meerdere bereiken: dan het hele bestand), false bij onzin of een bereik buiten het bestand
     */
    private function range(Request $request, ?int $size): array|false|null
    {
        $header = $request->headers->get('Range');

        if ($header === null || trim($header) === '') {
            return null;
        }

        // Zonder bekende grootte is er geen bereik te leveren: het hele bestand.
        if ($size === null) {
            return null;
        }

        $header = trim($header);

        // Meerdere bereiken ondersteunen we niet; het hele bestand is dan een geldig antwoord.
        if (preg_match('/^bytes=\s*\d*-\d*(\s*,\s*\d*-\d*)+$/', $header)) {
            return null;
        }

        if (! preg_match('/^bytes=(\d{0,18})-(\d{0,18})$/', $header, $match) || ($match[1] === '' && $match[2] === '')) {
            return false;
        }

        if ($match[1] === '') {
            // De laatste n bytes.
            $suffix = (int) $match[2];

            if ($suffix === 0 || $size === 0) {
                return false;
            }

            return [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $match[1];
        $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);

        if ($start >= $size || $end < $start) {
            return false;
        }

        return [$start, $end];
    }

    /**
     * Een stroom die op $start begint, zonder het bestand in te lezen. Op S3
     * vraagt dit alleen het bereik op; een andere schijf moet een stroom
     * geven waarin te springen valt (lokaal). Kan de schijf dat niet: null.
     *
     * @return resource|null
     */
    private function openAt(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path, int $start, int $end)
    {
        try {
            if ($disk instanceof \Illuminate\Filesystem\AwsS3V3Adapter) {
                $result = $disk->getClient()->getObject([
                    'Bucket' => $disk->getConfig()['bucket'] ?? null,
                    'Key' => $disk->path($path),
                    'Range' => "bytes={$start}-{$end}",
                    '@http' => ['stream' => true],
                ]);

                $stream = $result['Body']->detach();

                return is_resource($stream) ? $stream : null;
            }

            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                return null;
            }

            if (! (stream_get_meta_data($stream)['seekable'] ?? false) || fseek($stream, $start) !== 0) {
                fclose($stream);

                return null;
            }

            return $stream;
        } catch (Throwable) {
            return null;
        }
    }
}
