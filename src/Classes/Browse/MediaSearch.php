<?php

namespace Dashed\DashedFiles\Classes\Browse;

use Illuminate\Database\Eloquent\Builder;
use Dashed\DashedCore\Classes\QueryHelpers\TokenizedSearch;

/**
 * Breed zoeken in de mediabibliotheek. Titel, bestandsnaam, alt-tekst (de
 * spiegeltaal) en tags bepalen samen de relevantie, zoals overal in het CMS.
 * Daarnaast vindt een zoekterm ook een alt-tekst of titel in een andere taal:
 * die staan als tekst met JSON erin in `alt_texts` en `titles`. Ze tellen
 * niet mee in de relevantie, want de spiegeltaal staat er ook in en zou
 * anders dubbel wegen.
 *
 * Omdat het JSON als tekst is: een woord korter dan drie tekens gaat niet
 * naar die kolommen (het zou op de taalsleutels `"nl"` en `"en"` en op de
 * leestekens van JSON matchen), en het woord wordt gezocht in de vorm waarin
 * het in de kolom staat (een aanhalingsteken en een backslash zijn daar
 * ge-escapet), met de jokertekens van LIKE onschadelijk gemaakt.
 */
final class MediaSearch extends TokenizedSearch
{
    private const COLUMNS = ['title', 'file_name', 'alt_text'];

    private const TRANSLATIONS = ['alt_texts', 'titles'];

    private const MIN_TRANSLATION_TERM = 3;

    public static function applyTo(Builder $query, string $search): Builder
    {
        $relations = BrowseSchema::hasRelations() ? ['tags' => ['name']] : [];
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        $needle = mb_strtolower($search);
        $terms = preg_split('/\s+/', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [$needle];
        $patterns = BrowseSchema::hasTexts() ? self::translationPatterns($terms) : null;

        if ($patterns === null) {
            return TokenizedSearch::apply($query, $search, self::COLUMNS, relations: $relations);
        }

        // De sortering die apply() op een geneste query zet gaat verloren;
        // alleen de voorwaarden komen mee. Daarom hieronder apart.
        $query->where(function (Builder $either) use ($search, $relations, $patterns) {
            $either
                ->where(fn (Builder $main) => TokenizedSearch::apply($main, $search, self::COLUMNS, relations: $relations))
                ->orWhere(function (Builder $other) use ($patterns) {
                    // Elk woord ergens in de vertalingen.
                    foreach ($patterns as $pattern) {
                        $other->where(function (Builder $any) use ($pattern) {
                            foreach (self::TRANSLATIONS as $column) {
                                // Een eigen escape-teken: de backslash werkt in MySQL en SQLite niet hetzelfde.
                                $any->orWhereRaw('LOWER(`' . $column . "`) LIKE ? ESCAPE '!'", [$pattern]);
                            }
                        });
                    }
                });
        });

        return self::orderByRelevance($query, self::COLUMNS, [], $terms, $needle, $search);
    }

    /**
     * Het LIKE-patroon per woord voor de vertaalkolommen, of null als een
     * van de woorden te kort is om daar te zoeken (dan telt alleen de gewone
     * zoekopdracht).
     *
     * @param  array<int, string>  $terms
     * @return array<int, string>|null
     */
    private static function translationPatterns(array $terms): ?array
    {
        $patterns = [];

        foreach ($terms as $term) {
            if (mb_strlen($term) < self::MIN_TRANSLATION_TERM) {
                return null;
            }

            // Zoals het woord in de kolom staat: JSON-ge-encodeerd, zonder de aanhalingstekens eromheen.
            $encoded = json_encode($term, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            if (! is_string($encoded) || strlen($encoded) < 2) {
                return null;
            }

            $patterns[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], substr($encoded, 1, -1)) . '%';
        }

        return $patterns;
    }
}
