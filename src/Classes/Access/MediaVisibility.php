<?php

namespace Dashed\DashedFiles\Classes\Access;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Models\MediaFolder;
use Dashed\DashedFiles\Classes\MediaHelper;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Jobs\RelocateMediaItems;
use Dashed\DashedFiles\Classes\MediaPickerFileCache;
use Dashed\DashedFiles\Jobs\FlushMediaDependentCaches;

/**
 * Wat privé is en bij welke sites een map hoort, afgeleid van de mappenboom.
 *
 * Privé staat op twee plekken. Op de map staat wat een beheerder instelt,
 * geërfd naar alles eronder. Op het item staat hoe het NU behandeld wordt
 * (`is_private`): dat is wat het leespad leest, zonder de boom erbij. Een
 * item gaat direct dicht zodra zijn map privé wordt, en pas weer open als
 * zijn bestanden terug op de publieke schijf staan. `relocate_to` zegt
 * waar de bestanden nog heen moeten.
 *
 * De boom wordt in één query gelezen en per verzoek onthouden; per map of
 * per item komt er geen query bij. Eén uitzondering: bij het aanmaken van
 * een item wordt de map (met zijn ouders) vers uit de database gelezen
 * (isFolderPrivateFresh()), want een lang verzoek kan een boom onthouden
 * hebben van voordat de map privé werd.
 *
 * LET OP: privé is geen afscherming tussen collega's. Iedereen met toegang
 * tot de mediabibliotheek (`view_media`) kan privé-bestanden zien en
 * downloaden. Privé houdt bestanden van de website en uit de AI; wie een
 * bestand voor een collega wil afschermen heeft hier niets aan.
 */
final class MediaVisibility
{
    public const TO_PRIVATE = 'private';

    public const TO_PUBLIC = 'public';

    // Tot zoveel items verhuizen de bestanden in het verzoek zelf, daarboven in de wachtrij.
    public const INLINE_LIMIT = 5;

    private const MEMO = 'dashed-files.media-visibility';

    private const FOLDERS = 'filament_media_library_folders';

    /**
     * Of dit item afgeschermd is. Dicht bij twijfel: de vlag op het item,
     * en als de mediarij al geladen is ook de schijf waar het bestand staat.
     */
    public static function isPrivate(?Model $item): bool
    {
        if (! $item) {
            return false;
        }

        if ((bool) $item->getAttribute('is_private')) {
            return true;
        }

        if ($item instanceof MediaItem && $item->relationLoaded('media')) {
            foreach ($item->media as $media) {
                if (MediaStorage::isPrivateDisk($media->disk) || MediaStorage::isPrivateDisk($media->conversions_disk)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** De schijf waar een nieuw bestand van dit item heen moet. */
    public static function diskFor(?Model $item): string
    {
        if (! $item || ! BrowseSchema::hasAccess()) {
            return MediaStorage::publicDisk();
        }

        // Onderweg naar publiek: nieuwe bestanden meteen op hun eindbestemming.
        if ($item->getAttribute('relocate_to') === self::TO_PUBLIC) {
            return MediaStorage::publicDisk();
        }

        return MediaStorage::diskFor((bool) $item->getAttribute('is_private'));
    }

    /**
     * De mappen met wat er echt geldt, op id.
     *
     * @return array<int, array{parent_id: ?int, own_private: bool, private: bool, own_sites: ?array<int, string>, sites: ?array<int, string>}>
     */
    public function folders(): array
    {
        $request = app()->bound('request') ? request() : null;
        $memo = $request?->attributes->get(self::MEMO);

        if (is_array($memo)) {
            return $memo;
        }

        $map = BrowseSchema::hasAccess() ? $this->resolve($this->rows()) : [];
        $request?->attributes->set(self::MEMO, $map);

        return $map;
    }

    public static function forget(): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove(self::MEMO);
        }
    }

    /**
     * Van de rijen van de mappentabel naar wat er per map geldt. De ouder
     * komt uit parent_id; een verdwenen ouder of een kring telt als het
     * hoogste niveau, net als in de boom.
     *
     * @param  iterable<object>  $rows  met id, parent_id, is_private en site_ids
     * @return array<int, array{parent_id: ?int, own_private: bool, private: bool, own_sites: ?array<int, string>, sites: ?array<int, string>}>
     */
    public function resolve(iterable $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }

        $resolved = [];

        foreach (array_keys($byId) as $id) {
            $chain = [];
            $seen = [];
            $cursor = $id;

            while ($cursor !== null && isset($byId[$cursor]) && ! isset($resolved[$cursor]) && ! isset($seen[$cursor])) {
                $seen[$cursor] = true;
                $chain[] = $cursor;
                $cursor = $byId[$cursor]->parent_id === null ? null : (int) $byId[$cursor]->parent_id;
            }

            $parent = $cursor !== null && isset($resolved[$cursor]) ? $resolved[$cursor] : null;

            foreach (array_reverse($chain) as $node) {
                $ownSites = MediaSites::normalize($byId[$node]->site_ids ?? null);
                $ownPrivate = (bool) ($byId[$node]->is_private ?? false);

                $resolved[$node] = $parent = [
                    'parent_id' => $byId[$node]->parent_id === null ? null : (int) $byId[$node]->parent_id,
                    'own_private' => $ownPrivate,
                    'private' => $ownPrivate || ($parent['private'] ?? false),
                    'own_sites' => $ownSites,
                    'sites' => MediaSites::intersect($parent['sites'] ?? null, $ownSites),
                ];
            }
        }

        return $resolved;
    }

    /**
     * De boom zoals hij wordt als deze mappen een andere ouder, vlag of
     * sitelijst krijgen, zonder iets te schrijven.
     *
     * @param  array<int, array<string, mixed>>  $overrides  per map-id: parent_id, is_private en/of site_ids
     * @return array<int, array{parent_id: ?int, own_private: bool, private: bool, own_sites: ?array<int, string>, sites: ?array<int, string>}>
     */
    public function prospective(array $overrides): array
    {
        if (! BrowseSchema::hasAccess()) {
            return [];
        }

        $rows = $this->rows()->map(function (object $row) use ($overrides) {
            foreach ($overrides[(int) $row->id] ?? [] as $key => $value) {
                $row->{$key} = $value;
            }

            return $row;
        });

        return $this->resolve($rows);
    }

    /**
     * Welke mappen door deze wijziging van kant wisselen.
     *
     * @param  array<int, array<string, mixed>>  $overrides
     * @return array{to_private: array<int, int>, to_public: array<int, int>}
     */
    public function folderChanges(array $overrides): array
    {
        $before = $this->folders();
        $changes = ['to_private' => [], 'to_public' => []];

        foreach ($this->prospective($overrides) as $id => $folder) {
            $was = $before[$id]['private'] ?? false;

            if ($folder['private'] !== $was) {
                $changes[$folder['private'] ? 'to_private' : 'to_public'][] = $id;
            }
        }

        return $changes;
    }

    /** Zonder map (de hoofdmap) is nooit privé; een map die niet bestaat ook niet. */
    public function isFolderPrivate(?int $folderId): bool
    {
        return $folderId !== null && ($this->folders()[$folderId]['private'] ?? false);
    }

    /**
     * Hetzelfde antwoord, maar vers uit de database en zonder de onthouden
     * boom: de map zelf en elke ouder erboven. Voor het moment waarop een
     * nieuw item zijn schijf krijgt. Een kring of een keten dieper dan
     * honderd mappen telt als privé: bij twijfel dicht.
     */
    public function isFolderPrivateFresh(?int $folderId): bool
    {
        if ($folderId === null || ! BrowseSchema::hasAccess()) {
            return false;
        }

        $seen = [];
        $cursor = $folderId;

        while ($cursor !== null) {
            if (isset($seen[$cursor]) || count($seen) >= 100) {
                return true;
            }

            $seen[$cursor] = true;
            $row = DB::table(self::FOLDERS)->where('id', $cursor)->first(['parent_id', 'is_private']);

            if (! $row) {
                return false;
            }

            if ((bool) $row->is_private) {
                return true;
            }

            $cursor = $row->parent_id === null ? null : (int) $row->parent_id;
        }

        return false;
    }

    /**
     * @return array<int, string>|null
     */
    public function sitesOfFolder(?int $folderId): ?array
    {
        return $folderId === null ? null : ($this->folders()[$folderId]['sites'] ?? null);
    }

    /**
     * @return array<int, int>
     */
    public function privateFolderIds(): array
    {
        return array_keys(array_filter($this->folders(), fn (array $folder) => $folder['private']));
    }

    /**
     * De mappen die niet bij deze sites horen. Bestanden zonder map horen bij elke site.
     *
     * @param  array<int, string>  $sites
     * @return array<int, int>
     */
    public function foldersOutsideSites(array $sites): array
    {
        if ($sites === []) {
            return [];
        }

        return array_keys(array_filter($this->folders(), fn (array $folder) => ! MediaSites::matches($folder['sites'], $sites)));
    }

    /**
     * Wat er met deze items gebeurt als ze naar deze map gaan, zonder iets te wijzigen.
     *
     * @param  array<int, int>  $itemIds
     * @return array{to_private: array<int, int>, to_public: array<int, int>}
     */
    public function planMove(array $itemIds, ?int $folderId): array
    {
        $plan = ['to_private' => [], 'to_public' => []];

        if ($itemIds === [] || ! BrowseSchema::hasAccess()) {
            return $plan;
        }

        $private = $this->isFolderPrivate($folderId);

        foreach (array_chunk($itemIds, 1000) as $chunk) {
            foreach (MediaItem::withoutGlobalScopes()->toBase()->whereIn('id', $chunk)->get(['id', 'is_private', 'relocate_to']) as $row) {
                $closed = (bool) $row->is_private && $row->relocate_to !== self::TO_PUBLIC;

                if ($private && ! $closed) {
                    $plan['to_private'][] = (int) $row->id;
                } elseif (! $private && ((bool) $row->is_private && $row->relocate_to !== self::TO_PUBLIC)) {
                    $plan['to_public'][] = (int) $row->id;
                }
            }
        }

        return $plan;
    }

    /**
     * Wat er met de items in deze mappen gebeurt als hun map wel of niet
     * privé wordt, zonder iets te wijzigen.
     *
     * @param  array<int, int>  $folderIds
     * @return array<int, int> de id's die van kant wisselen (hooguit $limit)
     */
    public function itemsChanging(array $folderIds, bool $toPrivate, int $limit = 5000): array
    {
        if ($folderIds === [] || ! BrowseSchema::hasAccess()) {
            return [];
        }

        return MediaItem::withoutGlobalScopes()->toBase()
            ->whereIn('folder_id', $folderIds)
            ->where(fn ($query) => $toPrivate
                ? $query->where('is_private', false)->orWhere('relocate_to', self::TO_PUBLIC)
                : $query->where('is_private', true)->where(fn ($pending) => $pending->whereNull('relocate_to')->orWhere('relocate_to', self::TO_PRIVATE)))
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Brengt de items in lijn met hun map: wat in een privé-map staat gaat
     * direct dicht, wat in een publieke map staat en nog dicht is krijgt
     * de opdracht om terug te verhuizen. Daarna gaan de bestanden op pad:
     * een handvol in dit verzoek, de rest via de wachtrij.
     *
     * @param  array<int, int>|null  $itemIds  alleen deze items, of null voor alles in $folderIds
     * @param  array<int, int|null>|null  $folderIds  alleen deze mappen (null in de lijst is de hoofdmap), of null voor alle
     * @return array{to_private: int, to_public: int, queued: bool}
     */
    public function sync(?array $itemIds = null, ?array $folderIds = null): array
    {
        $result = ['to_private' => 0, 'to_public' => 0, 'queued' => false];

        if (! BrowseSchema::hasAccess() || $itemIds === [] || $folderIds === []) {
            return $result;
        }

        self::forget();
        $privateFolders = $this->privateFolderIds();

        $scope = function () use ($itemIds, $folderIds) {
            $query = MediaItem::withoutGlobalScopes()->toBase();

            if ($itemIds !== null) {
                $query->whereIn('id', $itemIds);
            }

            if ($folderIds !== null) {
                $ids = array_values(array_filter($folderIds, fn ($id) => $id !== null));
                $root = in_array(null, $folderIds, true);

                $query->where(fn ($folder) => $folder->whereIn('folder_id', $ids)->when($root, fn ($also) => $also->orWhereNull('folder_id')));
            }

            return $query;
        };

        // Naar privé: in een privé-map en nog open, of juist op weg terug naar publiek.
        $closing = $privateFolders === [] ? [] : $scope()
            ->whereIn('folder_id', $privateFolders)
            ->where(fn ($query) => $query->where('is_private', false)->orWhere('relocate_to', self::TO_PUBLIC))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (array_chunk($closing, 1000) as $chunk) {
            // Meteen dicht, en de opgeslagen adressen weg: het leespad geeft vanaf nu niets meer.
            $result['to_private'] += MediaItem::withoutGlobalScopes()->toBase()->whereIn('id', $chunk)
                ->update(array_merge(['is_private' => true, 'relocate_to' => self::TO_PRIVATE, 'conversion_urls' => null], self::freshProgress()));
        }

        // Naar publiek: niet (meer) in een privé-map en nog dicht. Het item
        // blijft dicht tot zijn bestanden terug zijn.
        $opening = $scope()
            ->where(fn ($query) => $query->whereNull('folder_id')->when($privateFolders !== [], fn ($also) => $also->orWhereNotIn('folder_id', $privateFolders), fn ($also) => $also->orWhereNotNull('folder_id')))
            ->where('is_private', true)
            ->where(fn ($query) => $query->whereNull('relocate_to')->orWhere('relocate_to', self::TO_PRIVATE))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (array_chunk($opening, 1000) as $chunk) {
            $result['to_public'] += MediaItem::withoutGlobalScopes()->toBase()->whereIn('id', $chunk)->update(array_merge(['relocate_to' => self::TO_PUBLIC], self::freshProgress()));
        }

        if ($closing !== []) {
            $this->forgetDelivered($closing);
        }

        $changed = array_merge($closing, $opening);

        if ($changed === []) {
            return $result;
        }

        MediaPickerFileCache::flush();

        if (count($changed) <= self::INLINE_LIMIT) {
            $relocator = app(MediaRelocator::class);

            foreach ($changed as $id) {
                // Mislukt het hier, dan blijft de opdracht staan en pakt de wachtrij of de volgende ronde hem op.
                if ($relocator->attempt($id) !== MediaRelocator::DONE) {
                    $result['queued'] = true;
                }
            }
        } else {
            $result['queued'] = true;
        }

        if ($result['queued']) {
            RelocateMediaItems::start();
        }

        return $result;
    }

    /**
     * Alles wat de site van deze items nog kan uitleveren weg: de cache van
     * het leespad (bij veel items de hele generatie) en de pagina's die het
     * item tonen.
     *
     * @param  array<int, int>  $ids
     */
    public function forgetDelivered(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        rescue(function () use ($ids) {
            $library = app(MediaLibrary::class);

            if (count($ids) > 50) {
                $library->forgetAllCached();
            } else {
                foreach ($ids as $id) {
                    $library->invalidate($id, 'visibility');
                }
            }

            foreach (array_slice($ids, 0, 2000) as $id) {
                MediaHelper::forgetInMemory((int) $id);
            }

            if (count($ids) > 2000) {
                MediaHelper::flushMemoryCache();
            }
        });

        rescue(fn () => count($ids) > 2000 ? FlushMediaDependentCaches::queueMany([]) : FlushMediaDependentCaches::queueMany($ids));
    }

    /** Hoeveel items nog op hun bestanden wachten (ook wat is opgegeven: dat wacht op een mens). */
    public function pendingCount(): int
    {
        return BrowseSchema::hasAccess()
            ? (int) MediaItem::withoutGlobalScopes()->toBase()->whereNotNull('relocate_to')->count()
            : 0;
    }

    /**
     * Of de minuuttaak iets te doen heeft: een item dat wacht en niet is
     * opgegeven, of een open item in een privé-map. In rust kost dit twee
     * kleine queries (wacht er iets, is er een privé-map), met privé-mappen
     * vier (de boom en één EXISTS op de index van folder_id erbij).
     */
    public function needsWork(): bool
    {
        if (! BrowseSchema::hasAccess()) {
            return false;
        }

        $waiting = MediaItem::withoutGlobalScopes()->toBase()->whereNotNull('relocate_to')
            ->when(BrowseSchema::hasRelocateProgress(), fn ($query) => $query->whereNull('relocate_gave_up_at'))
            ->exists();

        return $waiting || $this->hasOpenItemsInPrivateFolders();
    }

    public function hasOpenItemsInPrivateFolders(): bool
    {
        return $this->openItemsInPrivateFolders(1) !== [];
    }

    /**
     * Items die open staan terwijl hun map (eigen of geërfd) privé is. Dat
     * hoort niet voor te komen; het is het vangnet voor een item dat langs
     * de haak bij het aanmaken glipte.
     *
     * @return array<int, int>
     */
    public function openItemsInPrivateFolders(int $limit = 50): array
    {
        if (! BrowseSchema::hasAccess() || ! DB::table(self::FOLDERS)->where('is_private', true)->exists()) {
            return [];
        }

        self::forget();
        $folders = $this->privateFolderIds();

        if ($folders === []) {
            return [];
        }

        return MediaItem::withoutGlobalScopes()->toBase()
            ->whereIn('folder_id', $folders)
            ->where('is_private', false)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Het vangnet zelf: zet open items in een privé-map dicht en hun
     * bestanden op pad.
     *
     * @return int hoeveel items dicht gingen
     */
    public function closeOpenItemsInPrivateFolders(): int
    {
        if (! $this->hasOpenItemsInPrivateFolders()) {
            return 0;
        }

        return $this->sync(null, $this->privateFolderIds())['to_private'];
    }

    /**
     * @return array<int, int> de items waarvan de verhuizing is opgegeven
     */
    public function givenUpIds(int $limit = 50): array
    {
        if (! BrowseSchema::hasAccess() || ! BrowseSchema::hasRelocateProgress()) {
            return [];
        }

        return MediaItem::withoutGlobalScopes()->toBase()->whereNotNull('relocate_to')->whereNotNull('relocate_gave_up_at')
            ->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array<int, int> de items die al langer dan zoveel seconden wachten en niet zijn opgegeven
     */
    public function waitingLongerThan(int $seconds = 3600, int $limit = 50): array
    {
        if (! BrowseSchema::hasAccess() || ! BrowseSchema::hasRelocateProgress()) {
            return [];
        }

        return MediaItem::withoutGlobalScopes()->toBase()->whereNotNull('relocate_to')->whereNull('relocate_gave_up_at')
            ->where('relocate_since', '<', now()->subSeconds($seconds))
            ->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Geeft opgegeven items een nieuwe kans (de knop "Nu verder gaan" en --force).
     */
    public function retryGivenUp(): int
    {
        if (! BrowseSchema::hasAccess() || ! BrowseSchema::hasRelocateProgress()) {
            return 0;
        }

        return MediaItem::withoutGlobalScopes()->toBase()->whereNotNull('relocate_to')->whereNotNull('relocate_gave_up_at')
            ->update(['relocate_gave_up_at' => null, 'relocate_attempts' => 0]);
    }

    /**
     * @return array<string, mixed> de voortgangskolommen van een verse opdracht
     */
    private static function freshProgress(): array
    {
        return BrowseSchema::hasRelocateProgress()
            ? ['relocate_since' => now(), 'relocate_attempts' => 0, 'relocate_gave_up_at' => null]
            : [];
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function rows(): \Illuminate\Support\Collection
    {
        // Ook de mappen in de prullenbak: de items daarin blijven dicht.
        return DB::table(self::FOLDERS)
            ->orderBy('id')
            ->get(['id', 'parent_id', 'is_private', 'site_ids']);
    }

    /** Het model van een map bestaat alleen om de tabelnaam vast te houden. */
    public static function folderTable(): string
    {
        return (new MediaFolder())->getTable();
    }
}
