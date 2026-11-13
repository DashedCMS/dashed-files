<?php

namespace Dashed\DashedFiles\Classes\Browse;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaTexts;
use Dashed\DashedFiles\Models\MediaFolder;
use Dashed\DashedFiles\Classes\MediaHelper;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Illuminate\Validation\ValidationException;
use Dashed\DashedFiles\Classes\MediaPickerFileCache;
use Dashed\DashedFiles\Jobs\FlushMediaDependentCaches;

/**
 * Bewerkingen vanuit de beheerpagina. Verwijderen is naar de prullenbak;
 * definitief weg kan alleen van daaruit.
 */
final class MediaBulk
{
    /**
     * @param  array<int, int|string>  $itemIds
     * @return int het aantal verplaatste items
     */
    public function moveItems(array $itemIds, ?int $folderId): int
    {
        if ($folderId !== null && ! DB::table((new MediaFolder())->getTable())->where('id', $folderId)->exists()) {
            throw ValidationException::withMessages(['folder_id' => __('De doelmap bestaat niet meer.')]);
        }

        $ids = [];

        foreach ($itemIds as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        if ($ids === []) {
            return 0;
        }

        $moved = MediaItem::withoutGlobalScopes()->toBase()
            ->whereIn('id', array_values($ids))
            ->where(fn ($query) => $folderId === null ? $query->whereNotNull('folder_id') : $query->whereNull('folder_id')->orWhere('folder_id', '!=', $folderId))
            ->update(['folder_id' => $folderId]);

        // Eén UPDATE vuurt geen model-events, en de kiezer onthoudt in welke map een bestand staat.
        MediaPickerFileCache::flush();

        return $moved;
    }

    /**
     * De titel in een taal; zonder taal de eerste taal van de installatie.
     */
    public function rename(MediaItem $item, ?string $title, ?string $locale = null): MediaItem
    {
        $title = trim((string) $title);

        if (mb_strlen($title) > 255) {
            throw ValidationException::withMessages(['title' => __('De titel mag maximaal :max tekens zijn.', ['max' => 255])]);
        }

        // Leeg is "geen eigen titel": de lijst toont dan de bestandsnaam.
        app(MediaLibrary::class)->setText($item, 'title', $locale ?? MediaTexts::firstLocale(), $title === '' ? null : $title);

        return $item;
    }

    /**
     * Naar de prullenbak. De mediarij en het bestand blijven staan en de
     * site blijft het item tonen tot het definitief weg is.
     */
    public function deleteItem(MediaItem $item, ?int $userId = null): void
    {
        if ($userId !== null && BrowseSchema::hasTrash()) {
            $item->deleted_by_user_id = $userId;
            $item->trash_batch = null;
            $item->saveQuietly();
        }

        // Via het model: de deleted-haken legen de kiezercache en dashed-core
        // biedt "Ongedaan maken" aan. Deze klasse is de ingang van de
        // mediaschermen (en van samenvoegen): `panel`, dat ruimt nooit
        // vanzelf op (MediaTrashOrigin).
        MediaTrashOrigin::as(MediaTrashOrigin::PANEL, fn () => $item->delete());

        $this->afterTrashing([(int) $item->getKey()]);
    }

    /**
     * Na het weggooien: de cache van het item om, zodat het leespad van de
     * site het voortaan als "in de prullenbak" uitlevert en elk gebruik
     * genoteerd wordt, en één gebundelde ronde die de pagina's opnieuw laat
     * renderen (een pagina uit de response-cache raakt het leespad niet).
     *
     * @param  array<int, int>  $ids
     */
    public function afterTrashing(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        rescue(fn () => app(MediaLibrary::class)->forgetCachedFor($ids));
        rescue(fn () => FlushMediaDependentCaches::queueMany($ids));
        MediaFolderTree::forgetDuplicatesMemo();
    }

    /**
     * Na het terugzetten: de markering "nog uitgeleverd" en de cache weg.
     *
     * @param  array<int, int>  $ids
     */
    public function afterRestoring(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        rescue(function () use ($ids) {
            app(MediaLibrary::class)->forgetCachedFor($ids);

            foreach (array_slice($ids, 0, 500) as $id) {
                Cache::forget(MediaLibrary::servedKey($id));
            }
        });

        MediaHelper::forgetServedNotes();
        MediaFolderTree::forgetDuplicatesMemo();
    }

    /**
     * Meerdere items in één keer naar de prullenbak, zonder model per item.
     *
     * @param  array<int, int|string>  $itemIds
     * @return int het aantal weggegooide items
     */
    public function deleteItems(array $itemIds, ?int $userId = null, ?string $batch = null): int
    {
        $ids = $this->ids($itemIds);

        if ($ids === [] || ! BrowseSchema::hasTrash()) {
            return 0;
        }

        $batch ??= (string) Str::uuid();
        $deleted = 0;

        foreach (array_chunk($ids, 1000) as $chunk) {
            $deleted += MediaItem::withoutGlobalScopes()->toBase()
                ->whereIn('id', $chunk)
                ->whereNull('deleted_at')
                ->update(array_merge(
                    ['deleted_at' => now(), 'deleted_by_user_id' => $userId, 'trash_batch' => $batch],
                    BrowseSchema::hasTrashOrigin() ? ['trashed_via' => MediaTrashOrigin::PANEL] : [],
                ));
        }

        MediaPickerFileCache::flush();
        $this->afterTrashing($ids);

        return $deleted;
    }

    /**
     * Terug uit de prullenbak. Bestaat de map niet meer, of ligt die zelf in
     * de prullenbak, dan komt het item in de hoofdmap.
     *
     * @param  array<int, int|string>  $itemIds
     * @return array{restored: int, moved_to_root: int}
     */
    public function restoreItems(array $itemIds): array
    {
        $ids = $this->ids($itemIds);

        if ($ids === [] || ! BrowseSchema::hasTrash()) {
            return ['restored' => 0, 'moved_to_root' => 0];
        }

        $restored = 0;
        $moved = 0;
        $folders = (new MediaFolder())->getTable();

        foreach (array_chunk($ids, 1000) as $chunk) {
            $moved += MediaItem::withoutGlobalScopes()->toBase()
                ->whereIn('id', $chunk)
                ->whereNotNull('deleted_at')
                ->whereNotNull('folder_id')
                ->whereNotIn('folder_id', fn ($query) => $query->select('id')->from($folders)->whereNull('deleted_at'))
                ->update(['folder_id' => null]);

            $restored += MediaItem::withoutGlobalScopes()->toBase()
                ->whereIn('id', $chunk)
                ->whereNotNull('deleted_at')
                ->update(array_merge(
                    ['deleted_at' => null, 'deleted_by_user_id' => null, 'trash_batch' => null],
                    BrowseSchema::hasServed() ? ['trashed_served_at' => null] : [],
                    BrowseSchema::hasTrashOrigin() ? ['trashed_via' => null] : [],
                ));
        }

        MediaPickerFileCache::flush();
        $this->afterRestoring($ids);

        return ['restored' => $restored, 'moved_to_root' => $moved];
    }

    /**
     * Zet alles terug wat in de prullenbak ligt, items en mappen. Voor de
     * terugschakeling naar het oude pakket, dat de prullenbak niet kent.
     *
     * @return array{items: int, folders: int}
     */
    public function restoreEverything(): array
    {
        if (! BrowseSchema::hasTrash()) {
            return ['items' => 0, 'folders' => 0];
        }

        $folders = DB::table((new MediaFolder())->getTable())->whereNotNull('deleted_at')->update(['deleted_at' => null, 'trash_batch' => null]);

        $items = MediaItem::withoutGlobalScopes()->toBase()->whereNotNull('deleted_at')->update(array_merge(
            ['deleted_at' => null, 'deleted_by_user_id' => null, 'trash_batch' => null],
            BrowseSchema::hasServed() ? ['trashed_served_at' => null] : [],
            BrowseSchema::hasTrashOrigin() ? ['trashed_via' => null] : [],
        ));

        if ($folders > 0) {
            rescue(fn () => app(MediaFolderTree::class)->rebuildPaths());
        }

        if ($items > 0) {
            // De payloads in de cache dragen nog "in de prullenbak".
            rescue(fn () => app(MediaLibrary::class)->forgetAllCached());
        }

        MediaPickerFileCache::flush();
        MediaHelper::forgetServedNotes();
        MediaFolderTree::forgetDuplicatesMemo();

        return ['items' => $items, 'folders' => $folders];
    }

    /**
     * Definitief weg, alleen vanuit de prullenbak. Via het model: Spatie
     * haalt het bestand en zijn formaten van de schijf en de forceDeleted-haak
     * ruimt tags, favorieten, de gebruiksindex en de cache op.
     *
     * @param  array<int, int|string>  $itemIds
     * @return int het aantal verwijderde items
     */
    public function purgeItems(array $itemIds): int
    {
        $ids = $this->ids($itemIds);
        $purged = 0;

        foreach (array_chunk($ids, 200) as $chunk) {
            // Zonder de bestandsscope: ook een item zonder bestand moet weg kunnen.
            foreach (MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->onlyTrashed()->whereKey($chunk)->get() as $item) {
                $item->forceDelete();
                $purged++;
            }
        }

        MediaPickerFileCache::flush();
        MediaFolderTree::forgetDuplicatesMemo();

        return $purged;
    }

    /**
     * @return array<int, int>
     */
    private function ids(array $itemIds): array
    {
        $ids = [];

        foreach ($itemIds as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }
}
