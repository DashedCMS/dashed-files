<?php

namespace Dashed\DashedFiles\Classes\Browse;

use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Dashed\DashedFiles\Models\MediaTag;
use Dashed\DashedFiles\Models\MediaItem;

/**
 * Tags en favorieten van media-items. Alles gaat per verzameling in een
 * vast aantal query's, ook voor honderden items tegelijk.
 */
final class MediaTagging
{
    public const MAX_TAGS_PER_ITEM = 30;

    private const CHUNK = 500;

    /**
     * @param  MediaItem|array<int, MediaItem|int|string>  $items
     * @param  array<int, mixed>  $names
     * @param  string  $mode  replace|add|remove
     */
    public function setTags(MediaItem|array $items, array $names, string $mode = 'replace'): void
    {
        if (! in_array($mode, ['replace', 'add', 'remove'], true)) {
            throw new InvalidArgumentException('Unknown tag mode: ' . $mode);
        }

        $itemIds = $this->existingItemIds($items instanceof MediaItem ? [$items] : $items);
        $names = self::normalizeNames($names);

        if ($itemIds === [] || ! BrowseSchema::hasRelations()) {
            return;
        }

        // Weghalen maakt geen nieuwe tags aan.
        $tagIds = array_values($this->tagIds($names, create: $mode !== 'remove'));
        $removed = [];

        if ($mode !== 'add') {
            foreach (array_chunk($itemIds, self::CHUNK) as $chunk) {
                $query = DB::table(BrowseSchema::ITEM_TAG)->whereIn('media_item_id', $chunk);
                $mode === 'remove' ? $query->whereIn('media_tag_id', $tagIds) : $query->whereNotIn('media_tag_id', $tagIds);

                $removed = array_merge($removed, (clone $query)->distinct()->pluck('media_tag_id')->all());
                $query->delete();
            }
        }

        if ($mode !== 'remove' && $tagIds !== []) {
            $this->attach($itemIds, $tagIds);
        }

        $this->pruneUnused(array_unique($removed));
    }

    /**
     * @return array<int, array{id: int, name: string, count: int}> meest gebruikt eerst
     */
    public function allTags(?string $search = null, int $limit = 50): array
    {
        if (! BrowseSchema::hasRelations()) {
            return [];
        }

        $search = mb_strtolower(trim((string) $search));

        return DB::table(BrowseSchema::TAGS . ' as t')
            ->leftJoin(BrowseSchema::ITEM_TAG . ' as p', 'p.media_tag_id', '=', 't.id')
            // Een eigen escape-teken: de backslash werkt in MySQL en SQLite niet hetzelfde.
            ->when($search !== '', fn ($query) => $query->whereRaw("t.name like ? escape '!'", ['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($search, 0, MediaTag::MAX_LENGTH)) . '%']))
            ->groupBy('t.id', 't.name')
            ->orderByRaw('count(p.media_item_id) desc')
            ->orderBy('t.name')
            ->limit(max(1, min(500, $limit)))
            ->get(['t.id', 't.name', DB::raw('count(p.media_item_id) as aggregate')])
            ->map(fn (object $row) => ['id' => (int) $row->id, 'name' => (string) $row->name, 'count' => (int) $row->aggregate])
            ->all();
    }

    /**
     * @return bool of het item na afloop favoriet is
     */
    public function toggleFavorite(int $itemId, int $userId): bool
    {
        if (! BrowseSchema::hasRelations()) {
            return false;
        }

        $deleted = DB::table(BrowseSchema::FAVORITES)->where('user_id', $userId)->where('media_item_id', $itemId)->delete();

        if ($deleted > 0) {
            return false;
        }

        $this->setFavorite([$itemId], $userId, true);

        return DB::table(BrowseSchema::FAVORITES)->where('user_id', $userId)->where('media_item_id', $itemId)->exists();
    }

    /**
     * @param  array<int, int|string>  $itemIds
     */
    public function setFavorite(array $itemIds, int $userId, bool $on): void
    {
        if ($userId <= 0 || ! BrowseSchema::hasRelations()) {
            return;
        }

        if (! $on) {
            foreach (array_chunk(self::ids($itemIds), self::CHUNK) as $chunk) {
                DB::table(BrowseSchema::FAVORITES)->where('user_id', $userId)->whereIn('media_item_id', $chunk)->delete();
            }

            return;
        }

        $now = now();

        foreach (array_chunk($this->existingItemIds($itemIds), self::CHUNK) as $chunk) {
            DB::table(BrowseSchema::FAVORITES)->insertOrIgnore(array_map(
                fn (int $id) => ['user_id' => $userId, 'media_item_id' => $id, 'created_at' => $now],
                $chunk,
            ));
        }
    }

    /**
     * Haalt de tag-koppelingen en favorieten van verwijderde items weg. De
     * tabellen hebben bewust geen foreign key naar de itemtabel.
     *
     * @param  array<int, int|string>  $itemIds
     */
    public function forgetItems(array $itemIds): void
    {
        $itemIds = self::ids($itemIds);

        if ($itemIds === [] || ! BrowseSchema::hasRelations()) {
            return;
        }

        foreach (array_chunk($itemIds, self::CHUNK) as $chunk) {
            $tagIds = DB::table(BrowseSchema::ITEM_TAG)->whereIn('media_item_id', $chunk)->distinct()->pluck('media_tag_id')->all();

            DB::table(BrowseSchema::ITEM_TAG)->whereIn('media_item_id', $chunk)->delete();
            DB::table(BrowseSchema::FAVORITES)->whereIn('media_item_id', $chunk)->delete();

            $this->pruneUnused($tagIds);
        }
    }

    /**
     * Getrimd, kleine letters, witruimte samengetrokken, ingekort en ontdubbeld.
     *
     * @param  array<int, mixed>  $names
     * @return array<int, string>
     */
    public static function normalizeNames(array $names): array
    {
        $clean = [];

        foreach ($names as $name) {
            if (! is_string($name) && ! is_numeric($name)) {
                continue;
            }

            $name = trim(mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_strtolower((string) $name))), 0, MediaTag::MAX_LENGTH));

            if ($name !== '') {
                $clean[$name] = $name;
            }
        }

        return array_slice(array_values($clean), 0, self::MAX_TAGS_PER_ITEM);
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, int> naam => id
     */
    private function tagIds(array $names, bool $create): array
    {
        if ($names === []) {
            return [];
        }

        $find = fn () => DB::table(BrowseSchema::TAGS)->whereIn('name', $names)->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower((string) $name) => (int) $id])->all();

        $ids = $find();
        $missing = array_values(array_diff($names, array_keys($ids)));

        if (! $create || $missing === []) {
            return $ids;
        }

        $now = now();

        // insertOrIgnore: een gelijktijdig verzoek kan dezelfde tag net hebben aangemaakt.
        DB::table(BrowseSchema::TAGS)->insertOrIgnore(array_map(
            fn (string $name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            $missing,
        ));

        $ids = $find();

        // MySQL vergelijkt zonder accenten: "cafe" bestaat al als iemand
        // "café" vraagt, en komt dan onder zijn eigen spelling terug.
        foreach (array_diff($names, array_keys($ids)) as $name) {
            $id = DB::table(BrowseSchema::TAGS)->where('name', $name)->value('id');

            if ($id !== null) {
                $ids[$name] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $itemIds
     * @param  array<int, int>  $tagIds
     */
    private function attach(array $itemIds, array $tagIds): void
    {
        $tagIds = array_values(array_unique($tagIds));

        foreach (array_chunk($itemIds, self::CHUNK) as $chunk) {
            $existing = [];

            foreach (DB::table(BrowseSchema::ITEM_TAG)->whereIn('media_item_id', $chunk)->get(['media_item_id', 'media_tag_id']) as $row) {
                $existing[(int) $row->media_item_id][(int) $row->media_tag_id] = true;
            }

            $rows = [];

            foreach ($chunk as $itemId) {
                $room = self::MAX_TAGS_PER_ITEM - count($existing[$itemId] ?? []);

                foreach ($tagIds as $tagId) {
                    if (isset($existing[$itemId][$tagId])) {
                        continue;
                    }

                    if ($room-- <= 0) {
                        break;
                    }

                    $rows[] = ['media_item_id' => $itemId, 'media_tag_id' => $tagId];
                }
            }

            foreach (array_chunk($rows, 1000) as $insert) {
                DB::table(BrowseSchema::ITEM_TAG)->insertOrIgnore($insert);
            }
        }
    }

    // Een tag die nergens meer aan hangt verdwijnt uit de lijst met suggesties.
    private function pruneUnused(array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }

        DB::table(BrowseSchema::TAGS)
            ->whereIn('id', $tagIds)
            ->whereNotIn('id', DB::table(BrowseSchema::ITEM_TAG)->whereIn('media_tag_id', $tagIds)->select('media_tag_id'))
            ->delete();
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, int> alleen id's van items die bestaan
     */
    private function existingItemIds(array $items): array
    {
        $ids = self::ids(array_map(fn ($item) => $item instanceof MediaItem ? $item->getKey() : $item, $items));

        if ($ids === []) {
            return [];
        }

        $existing = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $existing = array_merge($existing, MediaItem::withoutGlobalScopes()->toBase()->whereIn('id', $chunk)->pluck('id')->all());
        }

        return array_map('intval', $existing);
    }

    /**
     * @return array<int, int>
     */
    private static function ids(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }

        return array_values($clean);
    }
}
