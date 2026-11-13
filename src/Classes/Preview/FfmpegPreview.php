<?php

namespace Dashed\DashedFiles\Classes\Preview;

use Dashed\DashedFiles\Classes\MediaCapabilities;
use Dashed\DashedFiles\Contracts\MediaPreviewGenerator;

/**
 * Duur, afmetingen en een poster van een video, via ffprobe en ffmpeg.
 *
 * Een "video" van een gebruiker kan een afspeellijst zijn die ffmpeg lokale
 * bestanden of adressen laat lezen. Daarom drie sloten: de eerste bytes van
 * het bestand moeten een mp4/mov- of webm-container zijn (anders start er
 * niets), het invoerformaat wordt met `-f` afgedwongen (ffmpeg raadt dus
 * niet zelf dat het een afspeellijst is), en `-protocol_whitelist file`
 * sluit elk netwerkprotocol uit.
 *
 * Daarnaast twee grenzen op wat het programma mag verbruiken: één draad
 * (`-threads 1`) en een pixelgrens (`-max_pixels`), zodat een bestand dat
 * een beeld van 16000 bij 16000 aankondigt geen gigabyte geheugen kost.
 */
class FfmpegPreview implements MediaPreviewGenerator
{
    public const MIMES = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v'];

    public const MAX_WIDTH = 1280;

    public const PROBE_TIMEOUT = 8;

    public const POSTER_TIMEOUT = 15;

    // De poster komt van ongeveer 10 procent van de duur, hooguit zoveel seconden in.
    public const MAX_SEEK_SECONDS = 3.0;

    private const MOV = 'mov,mp4,m4a,3gp,3g2,mj2';

    private const WEBM = 'matroska,webm';

    public const DEFAULT_MAX_PIXELS = 50_000_000;

    /** @var array<string, ?float> de duur uit de laatste probe per pad, zodat poster() niet nog eens hoeft te vragen */
    private array $durations = [];

    /** De pixelgrens voor ffprobe en ffmpeg: groter dan dit wordt niet gedecodeerd. */
    public static function maxPixels(): int
    {
        $configured = config('dashed-files.preview.max_pixels');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : self::DEFAULT_MAX_PIXELS;
    }

    public function supports(string $mime): bool
    {
        return in_array(strtolower($mime), self::MIMES, true);
    }

    public function isAvailable(): bool
    {
        return MediaCapabilities::canRun('ffmpeg') && MediaCapabilities::canRun('ffprobe');
    }

    public function probe(string $localPath): array
    {
        $empty = ['width' => null, 'height' => null, 'duration' => null, 'pages' => null];
        $format = self::containerOf($localPath);
        $ffprobe = MediaCapabilities::ffprobe();

        if ($format === null || $ffprobe === null) {
            return $empty;
        }

        $result = SafeProcess::run($this->probeCommand($ffprobe, $localPath, $format), self::PROBE_TIMEOUT);

        if ($result === null || $result['exit'] !== 0) {
            $this->durations = [$localPath => null];

            return $empty;
        }

        $probed = self::parseProbe($result['out']);
        $this->durations = [$localPath => $probed['duration']];

        return $probed;
    }

    public function poster(string $localPath, string $targetPath): bool
    {
        $format = self::containerOf($localPath);
        $ffmpeg = MediaCapabilities::ffmpeg();

        if ($format === null || $ffmpeg === null) {
            return false;
        }

        // De duur uit de eerste probe: geen tweede keer ffprobe op hetzelfde bestand.
        $duration = array_key_exists($localPath, $this->durations) ? $this->durations[$localPath] : $this->probe($localPath)['duration'];
        $seek = $duration === null ? 0.0 : min(self::MAX_SEEK_SECONDS, max(0.0, $duration * 0.1));

        // Valt het moment buiten de video (of levert het geen beeld), dan het eerste beeld.
        foreach (array_unique([round($seek, 3), 0.0]) as $at) {
            @unlink($targetPath);

            // De tijd van de hele ronde is op: geen tweede poging.
            if (SafeProcess::expired()) {
                break;
            }

            SafeProcess::run($this->posterCommand($ffmpeg, $localPath, $targetPath, $format, (float) $at), self::POSTER_TIMEOUT);

            if (self::isJpeg($targetPath)) {
                return true;
            }
        }

        @unlink($targetPath);

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function probeCommand(string $ffprobe, string $localPath, string $format): array
    {
        return [
            $ffprobe, '-v', 'error', '-hide_banner',
            '-protocol_whitelist', 'file',
            '-threads', '1', '-max_pixels', (string) self::maxPixels(),
            '-f', $format,
            '-print_format', 'json', '-show_format', '-show_streams',
            '-i', $localPath,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function posterCommand(string $ffmpeg, string $localPath, string $targetPath, string $format, float $seek): array
    {
        return [
            $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-protocol_whitelist', 'file',
            // Voor de invoer: de decoder krijgt één draad en weigert een beeld boven de pixelgrens.
            '-threads', '1', '-max_pixels', (string) self::maxPixels(),
            '-f', $format,
            '-ss', number_format(max(0.0, $seek), 3, '.', ''),
            '-i', $localPath,
            '-map', '0:v:0', '-frames:v', '1', '-an', '-sn', '-dn',
            // Ook het filter en de encoder op één draad.
            '-filter_threads', '1', '-threads', '1',
            // Hooguit 1280 breed, nooit vergroten; een even hoogte.
            '-vf', 'scale=min(' . self::MAX_WIDTH . '\,iw):-2',
            '-c:v', 'mjpeg', '-q:v', '4',
            '-f', 'image2', '-update', '1',
            $targetPath,
        ];
    }

    /**
     * De container volgens de eerste bytes, als invoerformaat voor `-f`.
     * Null: geen mp4/mov en geen webm, dus niets om aan ffmpeg te geven.
     */
    public static function containerOf(string $localPath): ?string
    {
        $head = (string) @file_get_contents($localPath, false, null, 0, 16);

        if (strlen($head) < 12) {
            return null;
        }

        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
            return self::WEBM;
        }

        // Een mp4/mov begint met een box: vier bytes lengte, dan het type.
        return in_array(substr($head, 4, 4), ['ftyp', 'moov', 'mdat', 'free', 'skip', 'wide', 'pnot'], true) ? self::MOV : null;
    }

    /**
     * De uitvoer van ffprobe is onbetrouwbaar: alleen getallen binnen
     * redelijke grenzen komen erdoor.
     *
     * @return array{width: ?int, height: ?int, duration: ?float, pages: ?int}
     */
    public static function parseProbe(string $json): array
    {
        $empty = ['width' => null, 'height' => null, 'duration' => null, 'pages' => null];
        $data = strlen($json) <= SafeProcess::MAX_OUTPUT_BYTES ? json_decode($json, true, 16) : null;

        if (! is_array($data)) {
            return $empty;
        }

        $streams = is_array($data['streams'] ?? null) ? array_slice($data['streams'], 0, 64) : [];
        $video = null;

        foreach ($streams as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
                $video = $stream;

                break;
            }
        }

        if ($video === null) {
            return $empty;
        }

        $width = PreviewNumbers::dimension($video['width'] ?? null);
        $height = PreviewNumbers::dimension($video['height'] ?? null);

        // Een telefoon bewaart een staande video liggend met een draaiing erbij.
        $rotation = $video['tags']['rotate'] ?? null;

        foreach (is_array($video['side_data_list'] ?? null) ? array_slice($video['side_data_list'], 0, 16) : [] as $side) {
            if (is_array($side) && isset($side['rotation']) && is_numeric($side['rotation'])) {
                $rotation = $side['rotation'];
            }
        }

        if (is_numeric($rotation) && abs((int) round((float) $rotation)) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $duration = PreviewNumbers::duration($video['duration'] ?? null)
            ?? PreviewNumbers::duration(is_array($data['format'] ?? null) ? ($data['format']['duration'] ?? null) : null);

        return ['width' => $width, 'height' => $height, 'duration' => $duration, 'pages' => null];
    }

    public static function isJpeg(string $path): bool
    {
        if (! is_file($path) || filesize($path) < 100) {
            return false;
        }

        $size = @getimagesize($path);

        return is_array($size) && ($size[2] ?? null) === IMAGETYPE_JPEG && $size[0] > 0 && $size[1] > 0;
    }
}
