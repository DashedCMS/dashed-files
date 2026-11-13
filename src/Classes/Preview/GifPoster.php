<?php

namespace Dashed\DashedFiles\Classes\Preview;

use Throwable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Jobs\GenerateGifPoster;
use Dashed\DashedFiles\Classes\MediaPickerFileCache;
use Dashed\DashedFiles\Classes\Ingest\IngestPolicy;
use Dashed\DashedFiles\Classes\Ingest\IncomingMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * De statische poster van een geanimeerde gif, alleen voor het beheer: de
 * tegels en de lijst tonen het eerste beeld in plaats van de hele animatie.
 * De website krijgt de gif ongewijzigd (MediaPreviews::kindOf() kent hem
 * bewust niet, dus er komt geen `poster` in het leespad).
 *
 * Het is dezelfde voorvertoning als die van een video: een jpeg in de
 * collectie `preview` op hetzelfde item, gemaakt van het huidige bestand
 * (`preview_of`). Het eerste beeld lezen kan GD zelf, dus er is geen extern
 * programma nodig. Een gif zonder animatie krijgt geen poster: de
 * uitkomst staat als `gif_poster` op de mediarij, zodat hij niet elke keer
 * opnieuw bekeken wordt.
 */
class GifPoster
{
    public const PROPERTY = 'gif_poster';

    public const STILL = 'static';

    public const DONE = 'done';

    public const FAILED = 'failed';

    // De langste zijde van de poster: ruim boven een tegel op een scherm met dubbele dichtheid.
    public const MAX_SIDE = 400;

    // Wat GD in het geheugen decodeert (vier bytes per pixel): hierboven geen poster.
    public const MAX_PIXELS = 16_000_000;

    public const MAX_BYTES = 30 * 1024 * 1024;

    private const HOOK = 'dashed-files.gif-poster-requests-hooked';

    /** @var array<int, true> items waarvan het beheer een poster miste, tot het einde van het verzoek */
    private static array $requested = [];

    public static function isGif(?string $mime): bool
    {
        return strtolower((string) $mime) === 'image/gif';
    }

    /** Of er een poster gemaakt kan worden: eigen motor, de spiegelkolommen en GD. */
    public static function available(): bool
    {
        return MediaPreviews::available() && function_exists('imagecreatefromgif') && function_exists('imagejpeg');
    }

    /**
     * Of de gif nog bekeken moet worden: er is geen poster van het huidige
     * bestand en eerder is niet gebleken dat er niets te maken viel.
     */
    public static function isPending(MediaItem $item, ?Media $file): bool
    {
        if (! $file || ! self::isGif($file->mime_type) || ! self::available()) {
            return false;
        }

        if (in_array($file->getCustomProperty(self::PROPERTY), [self::STILL, self::FAILED], true)) {
            return false;
        }

        return app(MediaPreviews::class)->currentPreview($item) === null;
    }

    /**
     * Onthoudt dat het beheer een poster miste en zet aan het einde van het
     * verzoek per item een taak klaar, hoeveel tegels de pagina ook had.
     * Per item een pauze, zodat een bestand dat nooit lukt niet elke
     * paginalading een taak kost.
     */
    public static function requestMissing(int $itemId): void
    {
        if ($itemId <= 0 || ! self::available()) {
            return;
        }

        self::$requested[$itemId] = true;

        if (! app()->bound(self::HOOK)) {
            app()->instance(self::HOOK, true);
            app()->terminating(fn () => self::flushRequested());
        }
    }

    /** @return array<int, int> de items waarvoor een taak is klaargezet */
    public static function flushRequested(): array
    {
        $ids = array_slice(array_keys(self::$requested), 0, 200);
        self::$requested = [];
        $queued = [];

        foreach ($ids as $id) {
            if (rescue(fn () => Cache::add('dashed-files.gif-poster.' . $id, true, 1800), true, false)) {
                self::queue($id);
                $queued[] = $id;
            }
        }

        return $queued;
    }

    public static function forgetRequested(): void
    {
        self::$requested = [];
    }

    /**
     * Zet de taak voor dit item klaar. Met een synchrone wachtrij gaat dat
     * na het antwoord, zodat het beeldwerk niet in het verzoek zelf zit.
     */
    public static function queue(int $itemId): void
    {
        if ($itemId <= 0 || ! self::available()) {
            return;
        }

        rescue(function () use ($itemId) {
            if (! MediaPreviews::queueIsSync()) {
                GenerateGifPoster::dispatch($itemId);

                return;
            }

            GenerateGifPoster::dispatch($itemId)->afterResponse();
        });
    }

    /**
     * Het werk van de taak. Gooit niet; de uitkomst is een status:
     * `done`, `static` (geen animatie), `skipped`, `too-large`,
     * `unreadable` of `failed`.
     */
    public function generate(int $itemId): string
    {
        if (! self::available()) {
            return 'skipped';
        }

        $item = MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->withTrashed()->find($itemId);
        $file = $item?->file();

        if (! $item || ! $file || ! self::isGif($file->mime_type)) {
            return 'skipped';
        }

        if ((int) $file->size > self::MAX_BYTES) {
            $this->remember($file, self::FAILED);

            return 'too-large';
        }

        $local = $target = null;

        try {
            $local = $this->fetch($file);

            if ($local === null) {
                return 'unreadable';
            }

            if (IngestPolicy::sniff($local, (string) $file->file_name) !== 'image/gif') {
                $this->remember($file, self::FAILED);

                return 'skipped';
            }

            if (! self::isAnimated($local)) {
                $this->remember($file, self::STILL);

                return self::STILL;
            }

            $target = IncomingMedia::temporaryPath();

            if (! self::firstFrame($local, $target)) {
                $this->remember($file, self::FAILED);

                return self::FAILED;
            }

            $previews = app(MediaPreviews::class);
            $stored = $previews->storePoster($item, $target, 'gif', (int) $file->getKey());

            $this->remember($file, $stored ? self::DONE : self::FAILED);

            if ($stored) {
                MediaPickerFileCache::flush();
                app(MediaLibrary::class)->invalidate($item, 'preview');
            }

            return $stored ? self::DONE : self::FAILED;
        } catch (Throwable $exception) {
            report($exception);

            return self::FAILED;
        } finally {
            foreach (array_filter([$local, $target]) as $leftover) {
                if (is_file($leftover)) {
                    @unlink($leftover);
                }
            }
        }
    }

    /**
     * Of de gif meer dan één beeld heeft: elk beeld begint met een
     * afbeeldingsbeschrijving (0x2C) na de blokken ervoor. Dit loopt de
     * blokken af in plaats van naar bytes te zoeken, want 0x2C komt ook in
     * beelddata voor.
     */
    public static function isAnimated(string $path): bool
    {
        $stream = @fopen($path, 'rb');

        if (! $stream) {
            return false;
        }

        try {
            $head = fread($stream, 13);

            if (strlen($head) < 13 || ! str_starts_with($head, 'GIF8')) {
                return false;
            }

            $flags = ord($head[10]);

            if ($flags & 0x80) {
                fseek($stream, 3 * (2 ** (($flags & 7) + 1)), SEEK_CUR);
            }

            $frames = 0;

            while (! feof($stream)) {
                $byte = fgetc($stream);

                if ($byte === false || $byte === "\x3B") {
                    break;
                }

                if ($byte === "\x21") {
                    fgetc($stream);
                    self::skipSubBlocks($stream);
                } elseif ($byte === "\x2C") {
                    if (++$frames > 1) {
                        return true;
                    }

                    $descriptor = fread($stream, 9);

                    if (strlen($descriptor) < 9) {
                        break;
                    }

                    if (ord($descriptor[8]) & 0x80) {
                        fseek($stream, 3 * (2 ** ((ord($descriptor[8]) & 7) + 1)), SEEK_CUR);
                    }

                    fgetc($stream);
                    self::skipSubBlocks($stream);
                } else {
                    break;
                }
            }

            return false;
        } finally {
            fclose($stream);
        }
    }

    /** @param  resource  $stream */
    private static function skipSubBlocks($stream): void
    {
        while (! feof($stream)) {
            $length = fgetc($stream);

            if ($length === false || $length === "\x00") {
                return;
            }

            fseek($stream, ord($length), SEEK_CUR);
        }
    }

    /** Het eerste beeld als jpeg op een witte ondergrond, hooguit MAX_SIDE breed of hoog. */
    public static function firstFrame(string $gifPath, string $jpegPath): bool
    {
        $size = @getimagesize($gifPath);

        if (! $size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return false;
        }

        $source = @imagecreatefromgif($gifPath);

        if (! $source) {
            return false;
        }

        try {
            $scale = min(1, self::MAX_SIDE / max($size[0], $size[1]));
            $width = max(1, (int) round($size[0] * $scale));
            $height = max(1, (int) round($size[1] * $scale));
            $canvas = imagecreatetruecolor($width, $height);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);

            return imagejpeg($canvas, $jpegPath, 75);
        } finally {
            unset($source, $canvas);
        }
    }

    private function remember(Media $file, string $state): void
    {
        rescue(function () use ($file, $state) {
            $current = Media::query()->whereKey($file->getKey())->first();

            if ($current && $current->collection_name === MediaItem::COLLECTION) {
                $current->setCustomProperty(self::PROPERTY, $state);
                $current->saveQuietly();
            }
        });
    }

    private function fetch(Media $file): ?string
    {
        $stream = rescue(fn () => Storage::disk($file->disk)->readStream($file->getPathRelativeToRoot()), null, false);

        if (! is_resource($stream)) {
            return null;
        }

        $local = IncomingMedia::temporaryPath();
        $target = @fopen($local, 'wb');
        $copied = $target ? stream_copy_to_stream($stream, $target, self::MAX_BYTES + 1) : false;

        fclose($stream);

        if ($target) {
            fclose($target);
        }

        if (! $copied || $copied > self::MAX_BYTES) {
            @unlink($local);

            return null;
        }

        return $local;
    }
}
