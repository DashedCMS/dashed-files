<?php

namespace Dashed\DashedFiles\Classes\Browse;

use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * De code kan live staan voordat de migraties gedraaid hebben. Alles wat
 * vanuit een haak op elke upload of elke verwijdering loopt vraagt hier
 * eerst of de kolommen en tabellen er zijn.
 */
final class BrowseSchema
{
    public const TAGS = 'dashed__media_tags';

    public const ITEM_TAG = 'dashed__media_item_tag';

    public const FAVORITES = 'dashed__media_favorites';

    public const USAGES = 'dashed__media_usages';

    public const MERGES = 'dashed__media_merges';

    public const RUNS = 'dashed__media_conversion_runs';

    private const MEMO = 'dashed-files.browse-schema';

    /** @var array<string, true> een "ja" geldt voor de rest van het proces */
    private static array $known = [];

    /** Of de gedeelde cache al in $known is gelezen (een keer per proces en verzoek). */
    private static bool $loaded = false;

    public static function hasTrash(): bool
    {
        return self::has('trash', fn () => Schema::hasColumn('filament_media_library', 'deleted_at') && Schema::hasColumn('filament_media_library_folders', 'deleted_at'));
    }

    /** De kolom die bijhoudt of de site een weggegooid bestand nog uitlevert. */
    public static function hasServed(): bool
    {
        return self::has('served', fn () => Schema::hasColumn('filament_media_library', 'trashed_served_at'));
    }

    /** Wie een item weggooide: het paneel of code (MediaTrashOrigin). */
    public static function hasTrashOrigin(): bool
    {
        return self::has('trash-origin', fn () => Schema::hasColumn('filament_media_library', 'trashed_via'));
    }

    public static function hasUsages(): bool
    {
        return self::has('usages', fn () => Schema::hasTable(self::USAGES));
    }

    public static function hasGuesses(): bool
    {
        return self::has('guesses', fn () => Schema::hasColumn(self::USAGES, 'is_guess'));
    }

    public static function hasMerges(): bool
    {
        return self::has('merges', fn () => Schema::hasTable(self::MERGES));
    }

    public static function hasMirror(): bool
    {
        return self::has('mirror', fn () => Schema::hasColumn('filament_media_library', 'file_name'));
    }

    /** De kolommen van het focuspunt (fase 7). */
    public static function hasFocal(): bool
    {
        return self::has('focal', fn () => Schema::hasColumn('filament_media_library', 'focal_x') && Schema::hasColumn('filament_media_library', 'focal_y'));
    }

    public static function hasEdits(): bool
    {
        return self::has('edits', fn () => Schema::hasColumn('filament_media_library', 'edits'));
    }

    /** De vertalingen van alt-tekst, bijschrift en titel (fase 8). */
    public static function hasTexts(): bool
    {
        return self::has('texts', fn () => Schema::hasColumn('filament_media_library', 'alt_texts') && Schema::hasColumn('filament_media_library', 'captions') && Schema::hasColumn('filament_media_library', 'titles'));
    }

    /** Duur, pagina's, eigen poster en het moment van de laatste voorvertoningsronde (fase 9b). */
    public static function hasPreview(): bool
    {
        return self::has('preview', fn () => Schema::hasColumn('filament_media_library', 'duration')
            && Schema::hasColumn('filament_media_library', 'pages')
            && Schema::hasColumn('filament_media_library', 'poster_item_id')
            && Schema::hasColumn('filament_media_library', 'preview_checked_at'));
    }

    /** Sites en privé op de map, en de stand per item (fase 10). Pas dan gelden ook de rechten. */
    public static function hasAccess(): bool
    {
        return self::has('access', fn () => Schema::hasColumn('filament_media_library_folders', 'site_ids')
            && Schema::hasColumn('filament_media_library_folders', 'is_private')
            && Schema::hasColumn('filament_media_library', 'is_private')
            && Schema::hasColumn('filament_media_library', 'relocate_to'));
    }

    /** De voortgang van een verhuizing: sinds wanneer, hoe vaak mislukt, opgegeven (fase 10, vervolg). */
    public static function hasRelocateProgress(): bool
    {
        return self::has('relocate-progress', fn () => Schema::hasColumn('filament_media_library', 'relocate_attempts')
            && Schema::hasColumn('filament_media_library', 'relocate_since')
            && Schema::hasColumn('filament_media_library', 'relocate_gave_up_at'));
    }

    public static function hasRuns(): bool
    {
        return self::has('runs', fn () => Schema::hasTable(self::RUNS));
    }

    public static function hasRelations(): bool
    {
        return self::has('relations', fn () => Schema::hasTable(self::ITEM_TAG) && Schema::hasTable(self::FAVORITES));
    }

    public static function hasPaths(): bool
    {
        return self::has('paths', fn () => Schema::hasColumn('filament_media_library_folders', 'path'));
    }

    public static function forget(): void
    {
        self::flushMemory();
        rescue(fn () => Cache::forget(self::cacheKey()), report: false);
    }

    /**
     * Alleen het geheugen van dit proces leeg, de gedeelde cache blijft: wat
     * een nieuw verzoek ziet. (forget() is voor "het schema is veranderd".)
     */
    public static function flushMemory(): void
    {
        self::$known = [];
        self::$loaded = false;

        if (app()->bound('request')) {
            request()->attributes->remove(self::MEMO);
        }
    }

    /**
     * Alleen de onthouden "nee": een wachtrijwerker heeft altijd hetzelfde
     * verzoek en zou een kolom die bij zijn start nog ontbrak (tussen deploy
     * en migrate) anders voor de rest van zijn leven missen.
     */
    public static function forgetMissing(): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove(self::MEMO);
        }
    }

    /**
     * Een "ja" wordt voor het hele proces onthouden, een "nee" voor de rest
     * van het verzoek: een site tussen deploy en migrate, en de tests van
     * andere pakketten, krijgen er zo niet bij elke aanroep een query bij.
     */
    private static function has(string $name, callable $callback): bool
    {
        if (isset(self::$known[$name])) {
            return true;
        }

        // Een "ja" staat ook in de gedeelde cache: zonder dat deed elk
        // Livewire-verzoek tien tot achttien schemavragen aan information_schema.
        // Een "nee" nooit: een kolom die nog moet komen (deploy voor migrate)
        // moet zichtbaar worden zodra hij er is.
        if (! self::$loaded) {
            self::$loaded = true;
            self::$known = array_merge(self::$known, self::readCache());
        }

        if (isset(self::$known[$name])) {
            return true;
        }

        $request = app()->bound('request') ? request() : null;
        $memo = $request ? (array) $request->attributes->get(self::MEMO, []) : [];

        if (array_key_exists($name, $memo)) {
            return false;
        }

        if (self::check($callback)) {
            self::$known[$name] = true;
            self::writeCache();

            return true;
        }

        if ($request) {
            $memo[$name] = false;
            $request->attributes->set(self::MEMO, $memo);
        }

        return false;
    }

    /** De sleutel draagt de databasenaam: een andere verbinding heeft een ander schema. */
    private static function cacheKey(): string
    {
        $database = rescue(fn () => (string) DB::connection()->getDatabaseName(), '', false);

        return 'dashed-files.browse-schema.' . md5(config('database.default') . '|' . $database);
    }

    /** @return array<string, true> */
    private static function readCache(): array
    {
        $stored = rescue(fn () => Cache::get(self::cacheKey()), [], false);

        return is_array($stored) ? array_filter($stored) : [];
    }

    private static function writeCache(): void
    {
        rescue(fn () => Cache::forever(self::cacheKey(), self::$known), report: false);
    }

    private static function check(callable $callback): bool
    {
        try {
            return (bool) $callback();
        } catch (Throwable) {
            return false;
        }
    }
}
