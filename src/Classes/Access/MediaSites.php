<?php

namespace Dashed\DashedFiles\Classes\Access;

use Dashed\DashedCore\Classes\Sites;

/**
 * Bij welke sites een map hoort. Leeg of niets betekent alle sites. Een map
 * erft van zijn ouders: de sites die echt gelden zijn de doorsnede van de
 * map zelf en alles erboven, en bestanden zonder map horen bij elke site.
 *
 * Dit ordent alleen het beheer (de beheerpagina, het keuzevenster en het
 * mediaveld). Het leespad van de site (getSingleMedia) kijkt er niet naar.
 */
final class MediaSites
{
    /**
     * @return array<string, string> site-id => naam, in de volgorde van de installatie
     */
    public static function all(): array
    {
        $sites = [];

        foreach ((array) rescue(fn () => Sites::getSites(), [], false) as $site) {
            if (is_array($site) && isset($site['id']) && (string) $site['id'] !== '') {
                $sites[(string) $site['id']] = (string) ($site['name'] ?? $site['id']);
            }
        }

        return $sites;
    }

    public static function isMultiSite(): bool
    {
        return count(self::all()) > 1;
    }

    public static function active(): ?string
    {
        $active = rescue(fn () => Sites::getActive(), null, false);

        return is_scalar($active) && isset(self::all()[(string) $active]) ? (string) $active : null;
    }

    /**
     * Van wat er in de kolom of in een verzoek staat naar een lijst bekende
     * site-id's, of null voor "alle sites". Een lijst die elke site noemt is
     * ook "alle sites".
     *
     * @return array<int, string>|null
     */
    public static function normalize(mixed $value): ?array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        $known = self::all();
        $clean = [];

        foreach ($value as $id) {
            if (is_scalar($id) && isset($known[(string) $id])) {
                $clean[(string) $id] = (string) $id;
            }
        }

        // In de volgorde van de installatie, zodat gelijke keuzes gelijk opgeslagen worden.
        $clean = array_values(array_intersect(array_keys($known), $clean));

        return $clean === [] || count($clean) === count($known) ? null : $clean;
    }

    /**
     * @param  array<int, string>|null  $parent
     * @param  array<int, string>|null  $own
     * @return array<int, string>|null null is "alle sites"; een lege lijst is "geen enkele" en kan alleen bij rommel in de data
     */
    public static function intersect(?array $parent, ?array $own): ?array
    {
        if ($own === null) {
            return $parent;
        }

        return $parent === null ? $own : array_values(array_intersect($own, $parent));
    }

    /**
     * @param  array<int, string>|null  $child
     * @param  array<int, string>|null  $parent
     */
    public static function isSubset(?array $child, ?array $parent): bool
    {
        // "Alle sites" op het kind is erven: dat past altijd.
        return $child === null || $parent === null || array_diff($child, $parent) === [];
    }

    /**
     * Of een map met deze sites zichtbaar is voor wie op deze sites filtert:
     * er moet er minstens één overeenkomen.
     *
     * @param  array<int, string>|null  $effective
     * @param  array<int, string>  $wanted
     */
    public static function matches(?array $effective, array $wanted): bool
    {
        return $effective === null || $wanted === [] || array_intersect($effective, $wanted) !== [];
    }

    /**
     * @param  array<int, string>|null  $ids
     */
    public static function label(?array $ids): string
    {
        if ($ids === null) {
            return __('Alle sites');
        }

        $names = self::all();

        return implode(', ', array_map(fn (string $id) => $names[$id] ?? $id, $ids));
    }
}
