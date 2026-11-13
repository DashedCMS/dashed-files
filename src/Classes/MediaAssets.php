<?php

namespace Dashed\DashedFiles\Classes;

/**
 * De stijl en het script van de mediabibliotheek, als twee statische
 * bestanden (resources/assets) die een eigen route van het pakket levert.
 * Geen publish en geen build: klantprojecten compileren vendor/dashed niet
 * mee. De URL draagt een hash van de inhoud, dus een nieuwe release heeft
 * een nieuwe URL en de browser mag het bestand een jaar bewaren.
 *
 * Per verzoek houdt deze klasse ook bij of de pagina de bibliotheek nodig
 * heeft (de beheerpagina, een mediaveld): de render hook aan het eind van
 * de body zet de bestanden er dan meteen bij, en anders een klein stukje
 * script dat ze pas ophaalt als er alsnog een veld in de pagina komt.
 */
final class MediaAssets
{
    public const ROUTE = 'media.assets';

    public const FILES = [
        'css' => 'dashed-media.css',
        'js' => 'dashed-media.js',
    ];

    public const CONTENT_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
    ];

    private const ATTRIBUTE = 'dashed-files.media-needed';

    /** @var array<string, string>|null */
    private static ?array $hashes = null;

    public static function path(string $extension): string
    {
        return dirname(__DIR__, 2) . '/resources/assets/' . self::FILES[$extension];
    }

    /** Twaalf tekens uit de inhoud van beide bestanden. */
    public static function version(): string
    {
        return substr(md5(implode('|', self::hashes())), 0, 12);
    }

    /** @return array<string, string> */
    private static function hashes(): array
    {
        return self::$hashes ??= array_map(fn (string $extension) => (string) md5_file(self::path($extension)), array_combine(array_keys(self::FILES), array_keys(self::FILES)));
    }

    /** Alleen het pad: geen host, dus geen schema dat achter een proxy kan afwijken. */
    public static function url(string $extension, ?string $version = null): string
    {
        $panel = \Filament\Facades\Filament::getCurrentPanel();

        return route($panel->generateRouteName(self::ROUTE), ['version' => $version ?? self::version(), 'file' => $extension], false);
    }

    public static function forget(): void
    {
        self::$hashes = null;
    }

    /** De pagina heeft de bibliotheek nodig: de beheerpagina, een mediaveld of het keuzevenster. */
    public static function markNeeded(): void
    {
        if (app()->bound('request')) {
            request()->attributes->set(self::ATTRIBUTE, true);
        }
    }

    public static function needed(): bool
    {
        return app()->bound('request') && request()->attributes->get(self::ATTRIBUTE) === true;
    }
}
