<?php

namespace Dashed\DashedFiles\Classes\Preview;

use Dashed\DashedFiles\Classes\MediaCapabilities;
use Dashed\DashedFiles\Contracts\MediaPreviewGenerator;

/**
 * Het aantal pagina's en een miniatuur van de eerste pagina van een pdf,
 * via Ghostscript. Altijd met -dSAFER (geen bestanden lezen of schrijven
 * buiten wat op de regel staat), -dBATCH en -dNOPAUSE, en op een vast vel:
 * een pdf met een pagina van honderd meter levert zo geen enorme bitmap op.
 *
 * Standaard UIT, ook als het programma er is: Ghostscript is een volledige
 * PostScript-vertolker en -dSAFER is in het verleden vaker omzeild. Een
 * server zet het bewust aan met `dashed-files.preview.ghostscript`
 * (DASHED_FILES_GHOSTSCRIPT=true).
 */
class GhostscriptPreview implements MediaPreviewGenerator
{
    public const TIMEOUT = 20;

    public const DPI = 96;

    public function supports(string $mime): bool
    {
        return strtolower($mime) === 'application/pdf';
    }

    public function isAvailable(): bool
    {
        return self::enabled() && MediaCapabilities::canRun('gs');
    }

    /** Of deze server Ghostscript bewust heeft aangezet. */
    public static function enabled(): bool
    {
        return (bool) config('dashed-files.preview.ghostscript', false);
    }

    public function probe(string $localPath): array
    {
        $empty = ['width' => null, 'height' => null, 'duration' => null, 'pages' => null];

        if (! self::isPdf($localPath)) {
            return $empty;
        }

        // Eerst zonder programma: dat is er altijd en start niets.
        $pages = PdfProbe::pages($localPath);
        $gs = self::enabled() ? MediaCapabilities::ghostscript() : null;

        if ($pages === null && $gs !== null) {
            $result = SafeProcess::run($this->pagesCommand($gs, $localPath), self::TIMEOUT);

            // Alleen een kaal getal op de laatste regel telt.
            if ($result !== null && $result['exit'] === 0 && preg_match('/^\s*(\d{1,7})\s*$/', (string) last(preg_split('/\R/', trim($result['out'])) ?: []), $match)) {
                $pages = PreviewNumbers::pages($match[1]);
            }
        }

        return array_merge($empty, ['pages' => $pages]);
    }

    public function poster(string $localPath, string $targetPath): bool
    {
        $gs = self::enabled() ? MediaCapabilities::ghostscript() : null;

        if ($gs === null || ! self::isPdf($localPath)) {
            return false;
        }

        @unlink($targetPath);
        SafeProcess::run($this->posterCommand($gs, $localPath, $targetPath), self::TIMEOUT);

        if (FfmpegPreview::isJpeg($targetPath)) {
            return true;
        }

        @unlink($targetPath);

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function pagesCommand(string $gs, string $localPath): array
    {
        // Het pad staat in een PostScript-tekst; het is onze eigen naam, maar toch ge-escapet.
        $escaped = strtr($localPath, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);

        return [
            $gs, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNOPROMPT', '-dNODISPLAY',
            '--permit-file-read=' . $localPath,
            '-c', '(' . $escaped . ') (r) file runpdfbegin pdfpagecount = quit',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function posterCommand(string $gs, string $localPath, string $targetPath): array
    {
        return [
            $gs, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNOPROMPT',
            '-sDEVICE=jpeg', '-dJPEGQ=82', '-r' . self::DPI,
            '-dFirstPage=1', '-dLastPage=1',
            '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
            // Een vast A4-vel waar de pagina in gepast wordt.
            '-dFIXEDMEDIA', '-dPDFFitPage', '-dDEVICEWIDTHPOINTS=595', '-dDEVICEHEIGHTPOINTS=842',
            '-sOutputFile=' . $targetPath,
            '-f', $localPath,
        ];
    }

    public static function isPdf(string $localPath): bool
    {
        return str_contains((string) @file_get_contents($localPath, false, null, 0, 1024), '%PDF-');
    }
}
