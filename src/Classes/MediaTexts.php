<?php

namespace Dashed\DashedFiles\Classes;

use Throwable;
use Illuminate\Database\Eloquent\Model;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Classes\Locales;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;

/**
 * Alt-tekst, bijschrift en titel per taal. De kolom met vertalingen
 * (`alt_texts`, `captions`, `titles`) is de bron voor alle talen; de oude
 * tekstkolom (`alt_text`, `caption`, `title`) is de spiegel van de
 * vastgelegde spiegeltaal (zie firstLocale()), want het oude pakket,
 * klantprojecten en de zoekindex lezen die.
 *
 * Bij het lezen wint de tekstkolom voor de eerste taal: wat er via een
 * massa-update langs de haken glipt blijft zo kloppen tot de herstelronde
 * (`dashed:media-texts --sync`) de vertalingen heeft bijgewerkt.
 *
 * Bewust geen cast op het model en geen JSON-functies van de database: het
 * pakketmodel leest dezelfde rij zonder cast, de kolom is tekst (één rij
 * met ongeldige JSON zou een query met een JSON-functie op MySQL laten
 * falen), en zo werkt alles gelijk op MySQL en SQLite.
 */
final class MediaTexts
{
    /** veld => [tekstkolom, kolom met vertalingen] */
    public const FIELDS = [
        'alt' => ['alt_text', 'alt_texts'],
        'caption' => ['caption', 'captions'],
        'title' => ['title', 'titles'],
    ];

    /** Een alt-tekst langer dan dit leest geen schermlezer meer voor. */
    public const AI_MAX = 200;

    /** De korte naam van een veld (`alt`, `caption`, `title`), of null als het geen tekstveld is. */
    public static function field(string $field): ?string
    {
        if (isset(self::FIELDS[$field])) {
            return $field;
        }

        foreach (self::FIELDS as $name => [$column, $json]) {
            if ($field === $column || $field === $json) {
                return $name;
            }
        }

        return null;
    }

    public static function column(string $field): string
    {
        return self::FIELDS[$field][0];
    }

    public static function jsonColumn(string $field): string
    {
        return self::FIELDS[$field][1];
    }

    /** De installatiebrede instelling (op de eerste site) met de taal waar de tekstkolommen de spiegel van zijn. */
    public const MIRROR_SETTING = 'media_text_mirror_locale';

    /** De tekstkolommen zijn varchar(255); de vertalingen volgen dezelfde grens. */
    public const MAX_LENGTH = 255;

    private const MIRROR_MEMO = 'dashed-files.media-text-mirror';

    /**
     * De SPIEGELTAAL: de taal waar de tekstkolommen (`alt_text`, `caption`,
     * `title`) de spiegel van zijn, en de terugval bij het lezen. Vastgelegd
     * in een instelling op het moment van de migratie en NIET bij elke
     * aanroep afgeleid van de eerste taal van de site: wie later een taal
     * toevoegt of de volgorde wijzigt zou anders de tekstkolom als een andere
     * taal gaan lezen en bij de eerstvolgende save een geldige vertaling
     * overschrijven. Wisselen kan alleen bewust, met
     * `dashed:media-texts --mirror=<taal>`.
     *
     * Ontbreekt de instelling (een installatie van voor deze instelling),
     * dan geldt de eerste taal van de eerste site en wordt die meteen
     * vastgelegd.
     */
    public static function firstLocale(): string
    {
        $request = app()->bound('request') ? request() : null;

        if ($request && is_string($memo = $request->attributes->get(self::MIRROR_MEMO))) {
            return $memo;
        }

        $locale = self::storedMirrorLocale();

        if ($locale === null) {
            $live = self::liveFirstLocale();
            $locale = $live ?? (string) config('app.locale', 'nl');

            // Alleen een taal die echt van de sites komt wordt vastgelegd,
            // nooit de terugval op de taal van de applicatie.
            if ($live !== null) {
                self::storeMirrorLocale($live);
            }
        }

        $request?->attributes->set(self::MIRROR_MEMO, $locale);

        return $locale;
    }

    /** De vastgelegde spiegeltaal, of null als er nog geen is vastgelegd. */
    public static function storedMirrorLocale(): ?string
    {
        try {
            $stored = Customsetting::get(self::MIRROR_SETTING, (string) Sites::getFirstSite()['id']);
        } catch (Throwable) {
            // Midden in een uitrol kan de instellingentabel ontbreken.
            return null;
        }

        return is_string($stored) && self::looksLikeLocale($stored) ? $stored : null;
    }

    public static function storeMirrorLocale(string $locale): void
    {
        if (! self::looksLikeLocale($locale)) {
            throw new \InvalidArgumentException('Invalid media text locale.');
        }

        try {
            Customsetting::set(self::MIRROR_SETTING, $locale, (string) Sites::getFirstSite()['id']);
        } catch (Throwable $exception) {
            // Lezen mag hier nooit op stuklopen; de volgende aanroep probeert het opnieuw.
            report($exception);
        }

        self::forgetMirror();
    }

    public static function forgetMirror(): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove(self::MIRROR_MEMO);
        }
    }

    /** De eerste taal van de eerste site zoals die NU is ingesteld, of null als de talen niet te lezen zijn. */
    public static function liveFirstLocale(): ?string
    {
        try {
            $id = Locales::getFirstLocale()['id'] ?? null;
        } catch (Throwable) {
            $id = null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Alle actieve talen van de installatie, de spiegeltaal voorop (als die
     * nog actief is).
     *
     * @return array<int, string>
     */
    public static function locales(): array
    {
        try {
            $ids = array_values(array_unique(array_map('strval', array_column(Locales::getLocales(), 'id'))));
        } catch (Throwable) {
            $ids = [];
        }

        $first = self::firstLocale();

        if ($ids === []) {
            return [$first];
        }

        return in_array($first, $ids, true) ? array_values(array_unique(array_merge([$first], $ids))) : $ids;
    }

    /**
     * De talen van één site.
     *
     * @return array<int, string>
     */
    public static function localesForSite(?string $siteId = null): array
    {
        try {
            $ids = array_column(Locales::getLocalesForSite($siteId), 'id');
        } catch (Throwable) {
            $ids = [];
        }

        return $ids === [] ? [self::firstLocale()] : array_values(array_unique(array_map('strval', $ids)));
    }

    public static function isMultilingual(): bool
    {
        return count(self::locales()) > 1;
    }

    /** @return array<string, string> taal => naam, voor een taalkiezer */
    public static function localeLabels(): array
    {
        $labels = [];

        try {
            foreach (Locales::getLocales() as $locale) {
                $labels[(string) $locale['id']] = (string) ($locale['native'] ?? $locale['name'] ?? $locale['id']);
            }
        } catch (Throwable) {
            // Zonder talen blijft alleen de eerste over.
        }

        $ordered = [];

        foreach (self::locales() as $id) {
            $ordered[$id] = $labels[$id] ?? $id;
        }

        return $ordered;
    }

    public static function isLocale(string $locale): bool
    {
        return in_array($locale, self::locales(), true);
    }

    /** De vorm van een taalcode, voor wie een taal van buiten de eigen lijst opslaat. */
    public static function looksLikeLocale(string $locale): bool
    {
        return preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale) === 1;
    }

    public static function hasColumns(): bool
    {
        return BrowseSchema::hasTexts();
    }

    /**
     * De ruwe kolom met vertalingen als array, zonder lege waarden.
     *
     * @return array<string, string>
     */
    public static function decode(mixed $json): array
    {
        if (is_string($json)) {
            $json = $json === '' ? null : json_decode($json, true);
        }

        if (! is_array($json)) {
            return [];
        }

        $clean = [];

        foreach ($json as $locale => $value) {
            if (is_string($locale) && is_scalar($value) && trim((string) $value) !== '') {
                $clean[$locale] = (string) $value;
            }
        }

        return $clean;
    }

    /** Leeg wordt null, nooit een lege lijst of een lege waarde per taal. */
    public static function encode(array $translations): ?string
    {
        $translations = self::decode($translations);

        // Leesbare tekens: zoeken met LIKE vindt zo ook een woord met een accent.
        return $translations === [] ? null : json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * De vertalingen van een veld uit de waarden van één rij. De tekstkolom
     * wint voor de eerste taal.
     *
     * @return array<string, string>
     */
    public static function fromRow(string $field, mixed $text, mixed $json): array
    {
        $translations = self::decode($json);
        $first = self::firstLocale();

        if (is_scalar($text) && trim((string) $text) !== '') {
            // Op zijn plek als de taal er al staat, anders vooraan: de volgorde is geen betekenis.
            $translations = isset($translations[$first])
                ? array_replace($translations, [$first => (string) $text])
                : [$first => (string) $text] + $translations;
        } else {
            unset($translations[$first]);
        }

        return $translations;
    }

    /**
     * @return array<string, string>
     */
    public static function of(object|array $item, string $field): array
    {
        [$column, $json] = self::FIELDS[$field];

        if ($item instanceof Model) {
            return self::fromRow($field, $item->getAttribute($column), $item->getAttribute($json));
        }

        $item = (array) $item;

        return self::fromRow($field, $item[$column] ?? null, $item[$json] ?? null);
    }

    /** De gevraagde taal, anders de eerste taal, anders null. */
    public static function resolve(array $translations, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        return $translations[$locale] ?? $translations[self::firstLocale()] ?? null;
    }

    /**
     * De talen waarin dit veld leeg is.
     *
     * @param  array<int, string>|null  $locales
     * @return array<int, string>
     */
    public static function missing(array $translations, ?array $locales = null): array
    {
        return array_values(array_filter($locales ?? self::locales(), fn (string $locale) => ! isset($translations[$locale])));
    }

    /**
     * De haak op `saving`, voor het eigen model en dat van het pakket: houdt
     * de tekstkolom en de eerste taal in de vertalingen gelijk, welke van de
     * twee er ook geschreven is. Wie beide in dezelfde save zet (setTexts)
     * krijgt ze ongewijzigd terug.
     */
    public static function mirror(Model $item): void
    {
        $dirty = [];

        foreach (self::FIELDS as [$column, $json]) {
            if ($item->isDirty($column) || $item->isDirty($json)) {
                $dirty[] = [$column, $json];
            }
        }

        if ($dirty === [] || ! self::hasColumns()) {
            return;
        }

        $first = self::firstLocale();

        foreach ($dirty as [$column, $json]) {
            if ($item->isDirty($column) && $item->isDirty($json)) {
                continue;
            }

            $translations = self::decode($item->getAttribute($json));

            if ($item->isDirty($column)) {
                $text = $item->getAttribute($column);

                if (is_scalar($text) && trim((string) $text) !== '') {
                    $translations = isset($translations[$first])
                        ? array_replace($translations, [$first => (string) $text])
                        : [$first => (string) $text] + $translations;
                } else {
                    unset($translations[$first]);
                }

                $item->setAttribute($json, self::encode($translations));

                continue;
            }

            $item->setAttribute($column, $translations[$first] ?? null);
        }
    }

    /** Afkappen op een woordgrens, zonder puntjes erachter. */
    public static function clip(string $text, int $max = self::AI_MAX): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max + 1);
        $space = mb_strrpos($cut, ' ');

        // Eén heel lang woord: dan toch midden in het woord.
        $cut = $space !== false && $space > 0 ? mb_substr($cut, 0, $space) : mb_substr($text, 0, $max);

        return rtrim($cut, " \t,;:-");
    }
}
