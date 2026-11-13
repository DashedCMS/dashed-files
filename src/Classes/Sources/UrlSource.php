<?php

namespace Dashed\DashedFiles\Classes\Sources;

use Throwable;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Classes\Ingest\IngestResult;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;

/**
 * Een afbeelding van een geplakt adres. Het ophalen gaat door de wachter
 * van dashed-core (alleen https, poort 443, geen intern adres, ook niet na
 * DNS of een doorverwijzing, 25 MB, 20 seconden) en daarna door de inname
 * met bron `url`: alleen rasterafbeeldingen, nooit svg.
 *
 * Wat er misgaat bij het bereiken van het adres geeft altijd dezelfde
 * melding. Anders is dit veld een manier om uit te vinden welke adressen in
 * het eigen netwerk bestaan.
 */
class UrlSource implements MediaSource
{
    public const KEY = 'url';

    public const TIMEOUT = 20;

    /** De totale tijd voor het ophalen, over DNS, doorverwijzingen en body samen. */
    public static function deadline(): int
    {
        $value = config('dashed-files.source_limits.url_deadline');

        return is_numeric($value) && (int) $value > 0 ? min(60, (int) $value) : self::TIMEOUT;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Van URL');
    }

    public function icon(): string
    {
        return 'heroicon-o-link';
    }

    public function kind(): string
    {
        return self::KIND_URL;
    }

    public function isAvailable(?string $siteId = null): bool
    {
        return MediaSourceSettings::enabled(self::KEY, $siteId) && class_exists('Dashed\\DashedCore\\Http\\SafeDownloader');
    }

    public function browserConfig(?string $siteId = null): array
    {
        return [
            'title' => __('Afbeelding van een URL'),
            'placeholder' => 'https://',
            'help' => __('Plak het https-adres van een afbeelding (jpg, png, gif, webp, heic of avif, tot 25 MB). Het bestand wordt naar de eigen bibliotheek gekopieerd, in de map waar je nu staat.'),
            'button' => __('Importeren'),
        ];
    }

    public function diagnostics(?string $siteId = null): array
    {
        return [
            'enabled' => MediaSourceSettings::enabled(self::KEY, $siteId),
            'downloader' => class_exists('Dashed\\DashedCore\\Http\\SafeDownloader'),
        ];
    }

    /**
     * @throws MediaSourceException
     * @throws MediaRejectedException  een oordeel over de inhoud van een bestand dat wel is opgehaald
     */
    public function import(string $url, ?int $folderId = null, ?int $userId = null): IngestResult
    {
        $url = trim($url);

        // Een toets op de vorm van wat er getypt is; zegt niets over wat er op dat adres staat.
        if ($url === '' || strlen($url) > 2000 || ! preg_match('#^https?://[^\s/]+#i', $url)) {
            throw new MediaSourceException(__('Vul een volledig https-adres in, bijvoorbeeld https://voorbeeld.nl/foto.jpg.'), 'invalid');
        }

        // Http valt onder dezelfde melding als elk ander adres dat niet mag:
        // er wordt niets opgehaald en er valt dus ook niets uit af te leiden.
        if (! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), $this->schemes(), true)) {
            throw self::unreachable();
        }

        $library = app(MediaLibrary::class);

        try {
            $incoming = $library->download($url, $folderId, 'url', [], ['timeout' => self::deadline()]);
        } catch (MediaRejectedException $exception) {
            throw $this->translate($exception);
        } catch (Throwable) {
            throw self::unreachable();
        }

        return $library->ingest($incoming->uploadedBy($userId));
    }

    /**
     * Ligt vast op https. Alleen een rookscript met een eigen lokale
     * bestandsserver overschrijft dit in een subklasse; de downloader weigert
     * http daarna nog steeds, tenzij dat script ook hem ruimte geeft.
     *
     * @return array<int, string>
     */
    protected function schemes(): array
    {
        return ['https'];
    }

    public static function unreachable(): MediaSourceException
    {
        return new MediaSourceException(__('Dit adres is niet bereikbaar of niet toegestaan. Alleen een https-adres buiten het eigen netwerk kan worden opgehaald.'), 'unreachable');
    }

    /**
     * Alleen "te groot" en "geen afbeelding" zijn een uitspraak over een
     * bestand dat een publieke server werkelijk terugstuurde. Al het andere
     * (geweigerd adres, time-out, foutstatus, doorverwijzing) is één melding.
     */
    private function translate(MediaRejectedException $exception): MediaSourceException
    {
        $downloader = 'Dashed\\DashedCore\\Http\\SafeDownloader';
        $reason = $exception->getPrevious()?->getCode();

        if (defined($downloader . '::REASON_TOO_LARGE') && $reason === constant($downloader . '::REASON_TOO_LARGE')) {
            return new MediaSourceException(__('Dit bestand is te groot (maximaal 25 MB).'), 'too_large');
        }

        if (defined($downloader . '::REASON_TYPE') && $reason === constant($downloader . '::REASON_TYPE')) {
            return new MediaSourceException(__('Op dit adres staat geen afbeelding die hier is toegestaan. Is het een webpagina, plak dan het adres van de afbeelding zelf (jpg, png, gif, webp, heic of avif).'), 'type');
        }

        return self::unreachable();
    }
}
