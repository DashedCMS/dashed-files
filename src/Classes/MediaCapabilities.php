<?php

namespace Dashed\DashedFiles\Classes;

use Throwable;
use Illuminate\Support\Facades\Log;

/**
 * Wat de server met beeld kan: de driver, webp, avif en de hulpprogramma's
 * voor video en pdf. Per proces onthouden; `flush()` leegt dat.
 *
 * De metingen zelf lopen via `probe()`, zodat een test de server kan
 * nabootsen (`fake()`) zonder dat er een extensie voor nodig is.
 */
class MediaCapabilities
{
    /** @var array<string, mixed> */
    private static array $memo = [];

    /** @var array<string, mixed>|null nagebootste metingen, alleen voor tests */
    private static ?array $fake = null;

    // AVIF bleek in dit proces niet te werken (het resultaat was geen avif):
    // voor de rest van het proces uit, wat de meting ook zegt.
    private static ?string $avifDisabledBecause = null;

    public static function imageDriver(): string
    {
        return strtolower((string) config('media-library.image_driver', 'gd')) === 'imagick' ? 'imagick' : 'gd';
    }

    public static function webp(): bool
    {
        return self::remember('webp-' . self::imageDriver(), fn () => self::imageDriver() === 'imagick'
            ? self::imagickSupports('WEBP')
            : (bool) (self::probe('function:imagewebp') && (self::probe('gd_info')['WebP Support'] ?? false)));
    }

    /**
     * Of deze server AVIF kan schrijven. GD: de functie moet bestaan EN GD
     * moet met libavif gebouwd zijn; zonder dat laatste geeft `imageavif`
     * een fatale fout. Imagick: het formaat moet in de lijst staan.
     */
    public static function avif(): bool
    {
        if (self::$avifDisabledBecause !== null) {
            return false;
        }

        return self::remember('avif-' . self::imageDriver(), fn () => self::imageDriver() === 'imagick'
            ? self::imagickSupports('AVIF')
            : (bool) (self::probe('function:imageavif') && (self::probe('gd_info')['AVIF Support'] ?? false)));
    }

    /**
     * Of de beelddriver een bestand van dit type kan inlezen om er formaten
     * van te maken. Jpeg, png en webp altijd; avif, heic, tiff en bmp alleen
     * als de driver het echt kan. Gif en svg nooit: die worden als origineel
     * uitgeleverd. Al het andere (ico, psd) leest geen van beide drivers
     * betrouwbaar.
     */
    public static function canRead(string $mime): bool
    {
        $mime = strtolower($mime);

        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return true;
        }

        $format = ['image/avif' => 'AVIF', 'image/heic' => 'HEIC', 'image/heif' => 'HEIC', 'image/tiff' => 'TIFF', 'image/bmp' => 'BMP', 'image/x-ms-bmp' => 'BMP'][$mime] ?? null;

        if ($format === null) {
            return false;
        }

        return self::remember('read-' . self::imageDriver() . '-' . $format, fn () => match (true) {
            self::imageDriver() === 'imagick' => self::imagickSupports($format),
            $format === 'AVIF' => (bool) (self::probe('function:imagecreatefromavif') && (self::probe('gd_info')['AVIF Support'] ?? false)),
            $format === 'BMP' => (bool) (self::probe('function:imagecreatefrombmp') && (self::probe('gd_info')['BMP Support'] ?? false)),
            // GD leest geen heic of tiff.
            default => false,
        });
    }

    /** Zet AVIF voor de rest van dit proces uit, met één logregel. */
    public static function disableAvif(string $reason): void
    {
        if (self::$avifDisabledBecause === null) {
            Log::warning('Media: AVIF disabled for this process: ' . $reason);
        }

        self::$avifDisabledBecause = $reason;
    }

    public static function avifDisabledBecause(): ?string
    {
        return self::$avifDisabledBecause;
    }

    /** Het pad van ffmpeg, of null als het er niet is of niet te zoeken valt. */
    public static function ffmpeg(): ?string
    {
        return self::binary('ffmpeg');
    }

    /** Het pad van ffprobe, of null. */
    public static function ffprobe(): ?string
    {
        return self::binary('ffprobe');
    }

    /** Het pad van Ghostscript (`gs`), of null. */
    public static function ghostscript(): ?string
    {
        return self::binary('gs');
    }

    /**
     * Of PHP een extern programma mag starten: `proc_open` bestaat en staat
     * niet in `disable_functions`.
     */
    public static function canExec(): bool
    {
        return self::remember('exec', fn () => (bool) self::probe('function:proc_open')
            && ! in_array('proc_open', array_map('trim', explode(',', (string) self::probe('ini:disable_functions'))), true));
    }

    /**
     * De echte startproef: het programma (`ffmpeg`, `ffprobe` of `gs`) is
     * gevonden, start en meldt een versie. Een pad dat bestaat zegt nog
     * niets: op gedeelde hosting kan het starten zelf geweigerd worden.
     * Een uur onthouden in de cache en per proces, want de proef start een
     * programma.
     */
    public static function canRun(string $name): bool
    {
        if (! in_array($name, ['ffmpeg', 'ffprobe', 'gs'], true)) {
            return false;
        }

        return (bool) self::remember('run-' . $name, function () use ($name) {
            $binary = self::binary($name);

            if (self::$fake !== null) {
                return (bool) (self::$fake['run:' . $name] ?? ($binary !== null));
            }

            if ($binary === null || ! self::canExec()) {
                return false;
            }

            $probe = function () use ($binary, $name): bool {
                $result = \Dashed\DashedFiles\Classes\Preview\SafeProcess::run([$binary, $name === 'gs' ? '--version' : '-version'], 5);

                // Ghostscript meldt alleen een nummer, ffmpeg en ffprobe een regel met "version".
                return $result !== null && $result['exit'] === 0
                    && preg_match($name === 'gs' ? '/^\s*\d+\.\d+/' : '/\bversion\b/i', $result['out']) === 1;
            };

            try {
                return (bool) \Illuminate\Support\Facades\Cache::remember('dashed-files.can-run.' . md5($binary), 3600, $probe);
            } catch (Throwable) {
                return rescue($probe, false, false);
            }
        });
    }

    /** Of deze server zelf een poster van een video kan maken. */
    public static function videoPosters(): bool
    {
        return self::canRun('ffmpeg');
    }

    /** Of deze server zelf een miniatuur van een pdf kan maken. */
    public static function pdfThumbnails(): bool
    {
        return \Dashed\DashedFiles\Classes\Preview\GhostscriptPreview::enabled() && self::canRun('gs');
    }

    /**
     * @return array{driver: string, webp: bool, avif: bool, ffmpeg: ?string, ghostscript: ?string, exec: bool}
     */
    public static function summary(): array
    {
        return [
            'driver' => self::imageDriver(),
            'webp' => self::webp(),
            'avif' => self::avif(),
            'ffmpeg' => self::ffmpeg(),
            'ghostscript' => self::ghostscript(),
            'exec' => self::canExec(),
        ];
    }

    public static function flush(): void
    {
        self::$memo = [];
        self::$fake = null;
        self::$avifDisabledBecause = null;
    }

    /**
     * Bootst de server na. Sleutels: `function:<naam>` (bool), `gd_info`
     * (array), `imagick` (lijst formaten, of null voor "geen imagick"),
     * `ini:disable_functions` (tekst), `binary:<naam>` (pad of null).
     *
     * @param  array<string, mixed>  $probes
     */
    public static function fake(array $probes): void
    {
        self::$memo = [];
        self::$avifDisabledBecause = null;
        self::$fake = $probes;
    }

    private static function imagickSupports(string $format): bool
    {
        $formats = self::probe('imagick');

        return is_array($formats) && in_array($format, array_map('strtoupper', $formats), true);
    }

    private static function binary(string $name): ?string
    {
        return self::remember('binary-' . $name, function () use ($name) {
            if (self::$fake !== null) {
                return self::$fake['binary:' . $name] ?? null;
            }

            if (! self::canExec()) {
                return null;
            }

            // Een vast pad uit de configuratie, voor een server waar het
            // programma buiten het zoekpad staat.
            $configured = config('dashed-files.preview.binaries.' . $name);

            if (is_string($configured) && $configured !== '') {
                return is_file($configured) && is_executable($configured) ? $configured : null;
            }

            // Zonder de zoeker van symfony/process valt er niets eerlijks te
            // zeggen: dan is het antwoord "niet gevonden".
            if (! self::canExec() || ! class_exists(\Symfony\Component\Process\ExecutableFinder::class)) {
                return null;
            }

            try {
                return (new \Symfony\Component\Process\ExecutableFinder())->find($name);
            } catch (Throwable) {
                return null;
            }
        });
    }

    private static function probe(string $key): mixed
    {
        if (self::$fake !== null) {
            return self::$fake[$key] ?? (str_starts_with($key, 'function:') ? false : ($key === 'gd_info' ? [] : null));
        }

        try {
            return match (true) {
                str_starts_with($key, 'function:') => function_exists(substr($key, 9)),
                str_starts_with($key, 'ini:') => (string) ini_get(substr($key, 4)),
                $key === 'gd_info' => function_exists('gd_info') ? (array) gd_info() : [],
                $key === 'imagick' => class_exists(\Imagick::class) ? \Imagick::queryFormats('*') : null,
                default => null,
            };
        } catch (Throwable) {
            return $key === 'gd_info' ? [] : null;
        }
    }

    private static function remember(string $key, callable $callback): mixed
    {
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = $callback();
        }

        return self::$memo[$key];
    }
}
