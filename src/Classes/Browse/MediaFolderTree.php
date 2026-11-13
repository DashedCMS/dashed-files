<?php

namespace Dashed\DashedFiles\Classes\Browse;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Query\Builder;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Models\MediaFolder;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Illuminate\Validation\ValidationException;
use Dashed\DashedFiles\Classes\Access\MediaSites;
use Dashed\DashedFiles\Classes\MediaPickerFileCache;
use Dashed\DashedFiles\Classes\Access\MediaVisibility;
use Dashed\DashedFiles\Jobs\FlushMediaDependentCaches;
use Dashed\DashedFiles\Classes\Usage\MediaUsageIndexer;

/**
 * De mappenboom van de mediabibliotheek. Elke map draagt in `path` de id's
 * van wortel tot en met zichzelf (/3/17/42/), zodat een subboom één LIKE op
 * een prefix is. Het oude pakket kent die kolom niet: een map die daar
 * aangemaakt of verplaatst wordt, krijgt zijn pad via de haak in de
 * ServiceProvider en anders bij de eerstvolgende tree().
 */
final class MediaFolderTree
{
    public const MAX_NAME_LENGTH = 255;

    /**
     * De hele boom in één query op de mappen en één telling op de items.
     *
     * @return array{folders: array<int, array{id: int, name: string, parent_id: ?int, count: int, total: int, children: array}>, rootCount: int, allCount: int}
     */
    public function tree(bool $withPrivate = true): array
    {
        $rows = $this->rows();
        $paths = $this->expectedPaths($rows);

        $this->writePaths($this->changedPaths($rows, $paths));

        $access = BrowseSchema::hasAccess();
        // Wat er per map geldt (privé, sites), uit dezelfde rijen: geen query erbij.
        $this->access = $access ? app(MediaVisibility::class)->resolve($rows) : [];
        $this->moving = [];

        // De globale scope telt mee: alleen items met een bestand. In
        // dezelfde query hoeveel er nog op hun bestanden wachten.
        $counts = [];

        foreach (MediaItem::query()->toBase()
            ->selectRaw('folder_id, count(*) as aggregate' . ($access ? ', sum(case when relocate_to is not null then 1 else 0 end) as moving' : ''))
            ->groupBy('folder_id')
            ->get() as $row) {
            $counts[$row->folder_id ?? ''] = (int) $row->aggregate;

            if ($access && (int) $row->moving > 0) {
                $this->moving[$row->folder_id ?? ''] = (int) $row->moving;
            }
        }

        // Een keuzevenster zonder allowPrivate() kent de privé-mappen niet: de mappen niet, hun namen niet en hun aantallen niet.
        if (! $withPrivate) {
            foreach ($this->access as $id => $folder) {
                if ($folder['private']) {
                    unset($rows[$id], $paths[$id], $counts[$id], $this->moving[$id]);
                }
            }
        }

        $rootCount = 0;
        $allCount = 0;

        foreach ($counts as $folderId => $count) {
            $allCount += (int) $count;

            if ($folderId === '' || $folderId === null) {
                $rootCount = (int) $count;
            }
        }

        // De ouder komt uit het berekende pad en niet uit parent_id: zo
        // staat een map met een verdwenen ouder, of een map in een kring,
        // gewoon op het hoogste niveau in plaats van nergens.
        $children = [];

        foreach ($rows as $id => $row) {
            $children[$this->parentFromPath($paths[$id]) ?? 0][] = $id;
        }

        return [
            'folders' => $this->branch(0, $children, $rows, $counts),
            'rootCount' => $rootCount,
            'allCount' => $allCount,
        ];
    }

    /** @var array<int, array<string, mixed>> wat er per map geldt, tijdens het opbouwen van de boom */
    private array $access = [];

    /** @var array<int|string, int> per map hoeveel items nog op hun bestanden wachten */
    private array $moving = [];

    /**
     * De boom met wat de beheerpagina erbij toont: het aantal in de
     * prullenbak, of er dubbele bestanden zijn en of de gebruiksindex gevuld
     * is. Los van tree(), dat een keuzevenster ook gebruikt en dat bij twee
     * query's blijft.
     */
    public function manageTree(): array
    {
        $tree = $this->tree();
        $moving = array_sum($this->moving);

        // Bestanden die nog van of naar de privé-schijf moeten; alleen als er iets wacht.
        return array_merge($tree, $moving > 0 ? ['moving' => $moving] : [], [
            'trashCount' => $this->trashCount(),
            'hasDuplicates' => $this->hasDuplicates(),
            'usageReady' => rescue(fn () => app(MediaUsageIndexer::class)->isReady(), false, false),
        ]);
    }

    /** Items en losse mappen in de prullenbak, voor het getal bij de slimme map. */
    public function trashCount(): int
    {
        if (! BrowseSchema::hasTrash()) {
            return 0;
        }

        return (int) MediaItem::onlyTrashed()->toBase()->count()
            + (int) DB::table((new MediaFolder())->getTable())->whereNotNull('deleted_at')->count();
    }

    private const DUPLICATES_MEMO = 'media-library-has-duplicates';

    /**
     * Of er bestanden met dezelfde inhoud zijn; alleen dan toont de boom de
     * slimme map. Een minuut onthouden: de boom laadt na elke bewerking
     * opnieuw, en zonder duplicaten loopt deze query de hele index af.
     * Uploaden, weggooien, terugzetten en samenvoegen wissen het antwoord.
     */
    public function hasDuplicates(): bool
    {
        return (bool) rescue(fn () => Cache::remember(
            self::DUPLICATES_MEMO,
            60,
            fn () => MediaItemQuery::make(['smart' => 'duplicates'])->query()->toBase()->limit(1)->exists(),
        ), false, false);
    }

    public static function forgetDuplicatesMemo(): void
    {
        rescue(fn () => Cache::forget(self::DUPLICATES_MEMO), report: false);
    }

    public function create(string $name, ?int $parentId = null): MediaFolder
    {
        if ($parentId !== null && ! $this->folders()->where('id', $parentId)->exists()) {
            throw ValidationException::withMessages(['parent_id' => __('De bovenliggende map bestaat niet meer.')]);
        }

        $folder = MediaFolder::create([
            'name' => $this->cleanName($name, $parentId),
            'parent_id' => $parentId,
        ]);

        $this->syncPath((int) $folder->getKey());

        return $folder->refresh();
    }

    public function rename(MediaFolder $folder, string $name): MediaFolder
    {
        $folder->name = $this->cleanName($name, $folder->parent_id === null ? null : (int) $folder->parent_id, (int) $folder->getKey());
        $folder->save();

        return $folder;
    }

    public function move(MediaFolder $folder, ?int $newParentId): MediaFolder
    {
        $id = (int) $folder->getKey();

        if ($newParentId === $id) {
            throw ValidationException::withMessages(['parent_id' => __('Een map kan niet in zichzelf worden gezet.')]);
        }

        if ($newParentId !== null) {
            $parent = $this->folders()->where('id', $newParentId)->first(['id', 'path']);

            if (! $parent) {
                throw ValidationException::withMessages(['parent_id' => __('De doelmap bestaat niet meer.')]);
            }

            if (! $this->isPath($parent->path)) {
                $this->rebuildPaths();
                $parent = $this->folders()->where('id', $newParentId)->first(['id', 'path']);
            }

            if (str_contains((string) $parent->path, '/' . $id . '/')) {
                throw ValidationException::withMessages(['parent_id' => __('Een map kan niet in een eigen submap worden gezet.')]);
            }
        }

        $this->cleanName((string) $folder->name, $newParentId, $id);
        $this->assertSitesFit($id, $newParentId);

        $folder->parent_id = $newParentId;
        $folder->save();

        $this->syncPath($id);
        MediaVisibility::forget();

        return $folder->refresh();
    }

    /**
     * Een map mag alleen onder een ouder komen als hij en alles eronder
     * binnen de sites van die ouder blijft. Weigeren en niet stil inkorten:
     * verplaatsen hoort niet ongemerkt te veranderen bij welke site een map hoort.
     */
    private function assertSitesFit(int $folderId, ?int $newParentId): void
    {
        if (! BrowseSchema::hasAccess() || $newParentId === null) {
            return;
        }

        $map = app(MediaVisibility::class)->folders();
        $parentSites = $map[$newParentId]['sites'] ?? null;

        if ($parentSites === null) {
            return;
        }

        foreach ($this->subtreeIds($folderId) as $id) {
            if (! MediaSites::isSubset($map[$id]['own_sites'] ?? null, $parentSites)) {
                throw ValidationException::withMessages(['parent_id' => __('Deze map (of een submap) hoort bij een site waar de doelmap niet bij hoort. Pas eerst de sites van de map aan.')]);
            }
        }
    }

    /**
     * Zet de sites en de privé-vlag van een map. De sites moeten binnen die
     * van de ouder vallen. Krimpt een map, dan krimpen de mappen eronder mee:
     * een site die de ouder niet meer heeft valt bij elk kind uit de lijst,
     * en een kind dat zo niets overhoudt erft weer gewoon van zijn ouder. Zo
     * hoort een map nooit bij geen enkele site en blijft wat er opgeslagen
     * staat altijd een deel van wat erboven geldt.
     *
     * Verhuist geen bestanden: dat doet de aanroeper met MediaVisibility::sync().
     *
     * @param  array<int, string>|null  $sites  null is "alle sites" (erven)
     * @return array{pruned: int} het aantal submappen waarvan de sites zijn ingekort
     */
    public function updateSettings(MediaFolder $folder, ?array $sites, bool $private): array
    {
        if (! BrowseSchema::hasAccess()) {
            throw ValidationException::withMessages(['folder' => __('Sites en privé instellen kan pas nadat de database is bijgewerkt.')]);
        }

        $id = (int) $folder->getKey();
        $visibility = app(MediaVisibility::class);
        $map = $visibility->folders();
        $sites = MediaSites::normalize($sites);
        $parentSites = $folder->parent_id === null ? null : ($map[(int) $folder->parent_id]['sites'] ?? null);

        if (! MediaSites::isSubset($sites, $parentSites)) {
            throw ValidationException::withMessages(['sites' => __('Een map kan alleen bij sites horen waar de bovenliggende map ook bij hoort (:sites).', ['sites' => MediaSites::label($parentSites)])]);
        }

        $pruned = 0;

        DB::transaction(function () use ($id, $sites, $private, $parentSites, $map, &$pruned) {
            $this->folders()->where('id', $id)->update([
                'site_ids' => $sites === null ? null : json_encode(array_values($sites)),
                'is_private' => $private,
            ]);

            $effective = MediaSites::intersect($parentSites, $sites);

            if ($effective === null) {
                return;
            }

            // Van boven naar beneden: wat een kind overhoudt bepaalt wat zijn kinderen mogen houden.
            $allowed = [$id => $effective];
            $ids = array_values(array_diff($this->subtreeIds($id), [$id]));
            usort($ids, fn (int $a, int $b) => $this->depthIn($map, $a) <=> $this->depthIn($map, $b));

            foreach ($ids as $childId) {
                $above = $allowed[$map[$childId]['parent_id'] ?? 0] ?? $effective;
                $own = $map[$childId]['own_sites'] ?? null;

                if ($own === null) {
                    $allowed[$childId] = $above;

                    continue;
                }

                $kept = array_values(array_intersect($own, $above));

                if ($kept !== $own) {
                    $this->folders()->where('id', $childId)->update(['site_ids' => $kept === [] || count($kept) === count($above) ? null : json_encode($kept)]);
                    $pruned++;
                }

                $allowed[$childId] = $kept === [] ? $above : $kept;
            }
        });

        MediaVisibility::forget();
        MediaPickerFileCache::flush();

        return ['pruned' => $pruned];
    }

    private function depthIn(array $map, int $id): int
    {
        $depth = 0;
        $seen = [];

        while (isset($map[$id]) && $map[$id]['parent_id'] !== null && ! isset($seen[$id]) && $depth < 100) {
            $seen[$id] = true;
            $id = (int) $map[$id]['parent_id'];
            $depth++;
        }

        return $depth;
    }

    /**
     * Gooit de map met zijn hele subboom en alle items daarin in één keer
     * weg. Alles krijgt hetzelfde kenmerk, zodat terugzetten van de map ook
     * precies dat terugzet.
     *
     * @return array{folders: int, items: int, batch: ?string}
     */
    public function delete(MediaFolder $folder, ?int $userId = null): array
    {
        $id = (int) $folder->getKey();

        if (! BrowseSchema::hasTrash()) {
            throw ValidationException::withMessages(['folder' => __('Mappen verwijderen kan pas nadat de database is bijgewerkt.')]);
        }

        $folderIds = $this->subtreeIds($id);
        $batch = (string) Str::uuid();
        $now = now();
        $items = 0;

        // Een map weggooien kan alleen vanuit de mediaschermen (MediaTrashOrigin).
        $origin = BrowseSchema::hasTrashOrigin() ? ['trashed_via' => MediaTrashOrigin::PANEL] : [];

        DB::transaction(function () use ($folderIds, $batch, $now, $userId, $origin, &$items) {
            foreach (array_chunk($folderIds, 500) as $folderChunk) {
                // In porties op id: één UPDATE over een map met honderdduizend
                // items houdt de tabel te lang vast.
                MediaItem::withoutGlobalScopes()->toBase()
                    ->whereIn('folder_id', $folderChunk)
                    ->whereNull('deleted_at')
                    ->select('id')
                    ->chunkById(1000, function ($rows) use ($batch, $now, $userId, $origin, &$items) {
                        $items += MediaItem::withoutGlobalScopes()->toBase()
                            ->whereIn('id', $rows->pluck('id')->all())
                            ->update(array_merge(['deleted_at' => $now, 'deleted_by_user_id' => $userId, 'trash_batch' => $batch], $origin));
                    });

                DB::table((new MediaFolder())->getTable())
                    ->whereIn('id', $folderChunk)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'trash_batch' => $batch]);
            }
        });

        MediaPickerFileCache::flush();

        // De cache van de items om en de pagina's opnieuw laten renderen. Bij
        // een grote map gaat de cache in één keer om in plaats van per item.
        if ($items > 0) {
            $itemIds = $items > 2000 ? [] : MediaItem::withoutGlobalScopes()->toBase()->where('trash_batch', $batch)->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($itemIds === []) {
                rescue(fn () => app(MediaLibrary::class)->forgetAllCached());
                rescue(fn () => FlushMediaDependentCaches::queueMany([]));
                self::forgetDuplicatesMemo();
            } else {
                app(MediaBulk::class)->afterTrashing($itemIds);
            }
        }

        return ['folders' => count($folderIds), 'items' => $items, 'batch' => $batch];
    }

    /**
     * Zet een map terug, met de submappen en items die in dezelfde handeling
     * zijn weggegooid.
     *
     * @return array{folders: int, items: int}
     */
    public function restore(int $folderId): array
    {
        $table = (new MediaFolder())->getTable();
        $folder = BrowseSchema::hasTrash() ? DB::table($table)->where('id', $folderId)->whereNotNull('deleted_at')->first(['id', 'name', 'parent_id', 'trash_batch']) : null;

        if (! $folder) {
            throw ValidationException::withMessages(['folder' => __('Deze map ligt niet in de prullenbak.')]);
        }

        $batch = filled($folder->trash_batch) ? (string) $folder->trash_batch : null;
        $result = ['folders' => 0, 'items' => 0];

        DB::transaction(function () use ($table, $folder, $batch, &$result) {
            $folderIds = $batch === null
                ? [(int) $folder->id]
                : DB::table($table)->where('trash_batch', $batch)->whereNotNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();

            // De ouder van de map zelf kan intussen weg zijn of ook in de
            // prullenbak liggen: dan komt de map op het hoogste niveau.
            $parentId = $folder->parent_id === null ? null : (int) $folder->parent_id;

            if ($parentId !== null && ! in_array($parentId, $folderIds, true) && ! $this->folders()->where('id', $parentId)->exists()) {
                $parentId = null;
            }

            $name = (string) $folder->name;
            $taken = fn (string $candidate) => $this->folders()->where('parent_id', $parentId)->whereRaw('LOWER(name) = ?', [mb_strtolower($candidate)])->exists();

            for ($i = 2; $taken($name) && $i < 50; $i++) {
                $name = mb_substr((string) $folder->name, 0, self::MAX_NAME_LENGTH - 6) . ' (' . $i . ')';
            }

            DB::table($table)->where('id', $folder->id)->update(['parent_id' => $parentId, 'name' => $name]);

            foreach (array_chunk($folderIds, 500) as $chunk) {
                $result['folders'] += DB::table($table)->whereIn('id', $chunk)->update(['deleted_at' => null, 'trash_batch' => null]);
            }

            if ($batch !== null) {
                $result['items'] = MediaItem::withoutGlobalScopes()->toBase()
                    ->where('trash_batch', $batch)
                    ->whereNotNull('deleted_at')
                    ->update(array_merge(
                        ['deleted_at' => null, 'deleted_by_user_id' => null, 'trash_batch' => null],
                        BrowseSchema::hasServed() ? ['trashed_served_at' => null] : [],
                        BrowseSchema::hasTrashOrigin() ? ['trashed_via' => null] : [],
                    ));
            }
        });

        $this->rebuildPaths();
        MediaPickerFileCache::flush();

        if ($result['items'] > 0) {
            // Welke items het waren is na de update niet meer te zien: de hele cache om.
            rescue(fn () => app(MediaLibrary::class)->forgetAllCached());
            \Dashed\DashedFiles\Classes\MediaHelper::forgetServedNotes();
            self::forgetDuplicatesMemo();
        }

        return $result;
    }

    /**
     * De mappen in de prullenbak, nieuwste eerst. Een submap die met zijn
     * ouder is weggegooid staat er niet los in: die komt met de ouder terug.
     *
     * @return array<int, array{id: int, name: string, deleted_at: ?string, items: int}>
     */
    public function trashed(int $limit = 200): array
    {
        if (! BrowseSchema::hasTrash()) {
            return [];
        }

        $table = (new MediaFolder())->getTable();

        $rows = DB::table($table . ' as f')
            ->whereNotNull('f.deleted_at')
            ->where(fn ($query) => $query
                ->whereNull('f.trash_batch')
                ->orWhereNull('f.parent_id')
                ->orWhereNotExists(fn ($parent) => $parent->selectRaw('1')->from($table . ' as p')->whereColumn('p.id', 'f.parent_id')->whereColumn('p.trash_batch', 'f.trash_batch')))
            ->orderByDesc('f.deleted_at')
            ->limit($limit)
            ->get(['f.id', 'f.name', 'f.deleted_at', 'f.trash_batch']);

        $batches = $rows->pluck('trash_batch')->filter()->unique()->values()->all();

        $counts = $batches === [] ? [] : MediaItem::withoutGlobalScopes()->toBase()
            ->whereIn('trash_batch', $batches)
            ->whereNotNull('deleted_at')
            ->groupBy('trash_batch')
            ->selectRaw('trash_batch, count(*) as aggregate')
            ->pluck('aggregate', 'trash_batch')
            ->all();

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'deleted_at' => $row->deleted_at ? \Illuminate\Support\Carbon::parse($row->deleted_at)->toIso8601String() : null,
            'items' => (int) ($counts[$row->trash_batch] ?? 0),
        ])->all();
    }

    /**
     * De map zelf en elke map eronder.
     *
     * @return array<int, int>
     */
    public function subtreeIds(int $folderId): array
    {
        $path = $this->pathOf($folderId);

        if ($path !== null) {
            // Het pad bestaat alleen uit cijfers en schuine strepen.
            return $this->folders()->where('path', 'like', $path . '%')->pluck('id')->map(fn ($id) => (int) $id)->all() ?: [$folderId];
        }

        // Zonder padkolom: de boom aflopen op parent_id.
        $ids = [$folderId => true];
        $frontier = [$folderId];

        while ($frontier !== []) {
            $frontier = array_values(array_filter(
                $this->folders()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all(),
                fn (int $id) => ! isset($ids[$id]),
            ));

            foreach ($frontier as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return array<int, array{id: int, name: string}> van wortel tot en met de map
     */
    public function breadcrumb(?int $folderId): array
    {
        if ($folderId === null || $folderId <= 0) {
            return [];
        }

        $path = $this->pathOf($folderId);

        if ($path === null) {
            return [];
        }

        $ids = $this->idsInPath($path);
        $names = $this->folders()->whereIn('id', $ids)->pluck('name', 'id');

        return array_values(array_map(
            fn (int $id) => ['id' => $id, 'name' => (string) ($names[$id] ?? '')],
            array_filter($ids, fn (int $id) => isset($names[$id])),
        ));
    }

    /**
     * Het pad van een map, aangevuld als het ontbreekt. Null als de map niet bestaat.
     */
    public function pathOf(int $folderId): ?string
    {
        if (! BrowseSchema::hasPaths()) {
            return null;
        }

        $row = $this->folders()->where('id', $folderId)->first(['id', 'path']);

        if ($row && ! $this->isPath($row->path)) {
            $this->rebuildPaths();
            $row = $this->folders()->where('id', $folderId)->first(['id', 'path']);
        }

        return $row && $this->isPath($row->path) ? (string) $row->path : null;
    }

    /**
     * Herberekent het pad van elke map en schrijft alleen wat afwijkt.
     *
     * @return int het aantal mappen waarvan het pad veranderde
     */
    public function rebuildPaths(): int
    {
        $rows = $this->rows();
        $changes = $this->changedPaths($rows, $this->expectedPaths($rows));

        $this->writePaths($changes);

        return count($changes);
    }

    /**
     * Zet het pad van één map goed nadat hij is aangemaakt of een andere
     * ouder kreeg, en schuift dan zijn hele subboom mee in één UPDATE.
     */
    public function syncPath(int $folderId): void
    {
        if (! BrowseSchema::hasPaths()) {
            return;
        }

        $row = $this->folders()->where('id', $folderId)->first(['id', 'parent_id', 'path']);

        if (! $row) {
            return;
        }

        $prefix = '/';

        if ($row->parent_id !== null) {
            $prefix = $this->folders()->where('id', $row->parent_id)->value('path');

            // Een ouder zonder pad, een verdwenen ouder of een kring: dan klopt een prefix nergens.
            if (! $this->isPath($prefix) || str_contains($prefix, '/' . $folderId . '/')) {
                $this->rebuildPaths();

                return;
            }
        }

        $new = $prefix . $folderId . '/';

        if ($row->path === $new) {
            return;
        }

        if (! $this->isPath($row->path)) {
            $this->folders()->where('id', $folderId)->update(['path' => $new]);

            // Een map van het oude pakket kan al submappen hebben.
            if ($this->folders()->where('parent_id', $folderId)->exists()) {
                $this->rebuildPaths();
            }

            return;
        }

        // Beide paden bestaan alleen uit cijfers en schuine strepen (isPath), dus letterlijk in de query.
        $rest = 'SUBSTR(path, ' . (strlen($row->path) + 1) . ')';
        $expression = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? "CONCAT('{$new}', {$rest})"
            : "'{$new}' || {$rest}";

        $this->folders()->where('path', 'like', $row->path . '%')->update(['path' => DB::raw($expression)]);
    }

    private function cleanName(string $name, ?int $parentId, ?int $exceptId = null): string
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('Geef de map een naam.')]);
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages(['name' => __('De naam van een map mag maximaal :max tekens zijn.', ['max' => self::MAX_NAME_LENGTH])]);
        }

        // MediaLibrary::folderIdForPath() splitst op de schuine streep.
        if (str_contains($name, '/')) {
            throw ValidationException::withMessages(['name' => __('De naam van een map mag geen schuine streep bevatten.')]);
        }

        $taken = $this->folders()
            ->where('parent_id', $parentId)
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('Er bestaat op deze plek al een map met deze naam.')]);
        }

        return $name;
    }

    /**
     * @return array<int, object> op id gesleuteld
     */
    private function rows(): array
    {
        $columns = BrowseSchema::hasPaths() ? ['id', 'name', 'parent_id', 'path', 'sort'] : ['id', 'name', 'parent_id'];

        if (BrowseSchema::hasAccess()) {
            $columns = array_merge($columns, ['is_private', 'site_ids']);
        }

        return $this->folders()->orderBy('id')->get($columns)->keyBy('id')->all();
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, string> id => pad
     */
    private function expectedPaths(array $rows): array
    {
        $paths = [];

        foreach ($rows as $id => $row) {
            $chain = [];
            $seen = [];
            $cursor = $id;

            // Omhoog tot een map waarvan het pad al bekend is, de wortel, een
            // verdwenen ouder of (in een kring) een map die al langskwam.
            while ($cursor !== null && isset($rows[$cursor]) && ! isset($paths[$cursor]) && ! isset($seen[$cursor])) {
                $seen[$cursor] = true;
                $chain[] = $cursor;
                $cursor = $rows[$cursor]->parent_id === null ? null : (int) $rows[$cursor]->parent_id;
            }

            $prefix = $cursor !== null && isset($paths[$cursor]) ? $paths[$cursor] : '/';

            foreach (array_reverse($chain) as $node) {
                $paths[$node] = $prefix .= $node . '/';
            }
        }

        return $paths;
    }

    private function changedPaths(array $rows, array $paths): array
    {
        if (! BrowseSchema::hasPaths()) {
            return [];
        }

        return array_filter($paths, fn (string $path, int $id) => ($rows[$id]->path ?? null) !== $path, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<int, string>  $changes  id => pad
     */
    private function writePaths(array $changes): void
    {
        foreach (array_chunk($changes, 200, true) as $chunk) {
            $cases = '';

            foreach ($chunk as $id => $path) {
                $cases .= ' WHEN ' . (int) $id . " THEN '" . $path . "'";
            }

            $this->folders()->whereIn('id', array_keys($chunk))->update(['path' => DB::raw('CASE id' . $cases . ' END')]);
        }
    }

    private function branch(int $parentId, array $children, array $rows, array $counts): array
    {
        $ids = $children[$parentId] ?? [];

        usort($ids, fn (int $a, int $b) => [(int) ($rows[$a]->sort ?? 0), mb_strtolower((string) $rows[$a]->name), $a]
            <=> [(int) ($rows[$b]->sort ?? 0), mb_strtolower((string) $rows[$b]->name), $b]);

        $branch = [];

        foreach ($ids as $id) {
            $nested = $this->branch($id, $children, $rows, $counts);
            $count = (int) ($counts[$id] ?? 0);

            $access = $this->access[$id] ?? null;
            $moving = (int) ($this->moving[$id] ?? 0) + array_sum(array_column($nested, 'moving'));

            // Alleen wat afwijkt van "publiek, alle sites, niets onderweg":
            // voor elke andere map blijft de rij precies wat hij was.
            $branch[] = [
                'id' => $id,
                'name' => (string) $rows[$id]->name,
                'parent_id' => $parentId ?: null,
                'count' => $count,
                'total' => $count + array_sum(array_column($nested, 'total')),
                'children' => $nested,
            ] + array_filter([
                'private' => $access['private'] ?? false,
                'own_private' => $access['own_private'] ?? false,
                'sites' => $access['sites'] ?? null,
                'own_sites' => $access['own_sites'] ?? null,
                'moving' => $moving,
            ], fn ($value) => $value !== null && $value !== false && $value !== 0);
        }

        return $branch;
    }

    private function parentFromPath(string $path): ?int
    {
        $ids = $this->idsInPath($path);

        return count($ids) > 1 ? $ids[count($ids) - 2] : null;
    }

    /**
     * @return array<int, int>
     */
    private function idsInPath(string $path): array
    {
        return array_map('intval', array_values(array_filter(explode('/', $path), fn (string $part) => $part !== '')));
    }

    private function isPath(mixed $path): bool
    {
        return is_string($path) && preg_match('#^/(\d+/)+$#', $path) === 1;
    }

    // Alleen wat niet in de prullenbak ligt: voor de boom, de paden en elke
    // toets op "bestaat deze map" is een weggegooide map er niet.
    private function folders(): Builder
    {
        return DB::table((new MediaFolder())->getTable())
            ->when(BrowseSchema::hasTrash(), fn (Builder $query) => $query->whereNull('deleted_at'));
    }
}
