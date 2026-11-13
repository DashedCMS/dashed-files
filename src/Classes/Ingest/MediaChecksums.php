<?php

namespace Dashed\DashedFiles\Classes\Ingest;

use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedFiles\Models\MediaItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Checksums en groottes van bestaande items. Een item van voor de inname
 * heeft geen van beide; hashen kost het lezen van het hele bestand, dus dat
 * gebeurt alleen waar het iets kan opleveren.
 */
class MediaChecksums
{
    private const INLINE_MAX_ITEMS = 5;

    private static bool $columnsExist = false;

    /**
     * De code kan live staan voordat de migratie gedraaid heeft.
     */
    public function available(): bool
    {
        if (self::$columnsExist) {
            return true;
        }

        try {
            return self::$columnsExist = Schema::hasColumn('filament_media_library', 'checksum')
                && Schema::hasColumn('filament_media_library', 'size');
        } catch (Throwable) {
            return false;
        }
    }

    public static function forgetColumns(): void
    {
        self::$columnsExist = false;
    }

    /**
     * Leest het bestand van een item als stroom van zijn schijf en bewaart
     * de checksum en de grootte. Null als het bestand er niet (meer) is.
     */
    public function hashItem(int $itemId): ?string
    {
        $media = $this->mediaQuery()->where('model_id', $itemId)->orderBy('id')->first();

        if (! $media) {
            return null;
        }

        try {
            $stream = Storage::disk($media->disk)->readStream($media->getPathRelativeToRoot());
        } catch (Throwable) {
            $stream = null;
        }

        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');
        $bytes = hash_update_stream($context, $stream);
        fclose($stream);
        $checksum = hash_final($context);

        // toBase(): geen events, geen cache die omgaat en updated_at blijft staan.
        MediaItem::withoutGlobalScopes()->toBase()->where('id', $itemId)->update([
            'checksum' => $checksum,
            'size' => $bytes,
        ]);

        return $checksum;
    }

    /**
     * Vóór een dubbelcontrole: oudere items van dezelfde grootte die nog
     * geen checksum hebben alsnog hashen, anders vindt de controle ze niet.
     * Geeft terug of er werk voor de wachtrij overblijft.
     */
    public function hashUnhashedOfSize(int $size): bool
    {
        if ($size <= 0) {
            return false;
        }

        $items = MediaItem::withoutGlobalScopes()->toBase();

        // De gewone weg: de grootte staat op het item en de index dekt dit.
        $ids = (clone $items)->where('size', $size)->whereNull('checksum')->orderBy('id')->limit(self::INLINE_MAX_ITEMS + 1)->pluck('id');

        // Zolang er items zonder grootte zijn (de aanvultaak is nog niet
        // klaar) ook op de mediarij zoeken. Dat is een scan zonder index, en
        // daarom alleen in die overgangstijd.
        $backfillPending = (clone $items)->whereNull('size')->exists();

        if ($backfillPending) {
            $ids = $ids->merge($this->mediaQuery()
                ->toBase()
                ->where('media.size', $size)
                ->whereIn('model_id', (clone $items)->whereNull('size')->whereNull('checksum')->select('id'))
                ->orderBy('model_id')
                ->limit(self::INLINE_MAX_ITEMS + 1)
                ->pluck('model_id'));
        }

        $ids = $ids->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return $backfillPending;
        }

        // Alle kandidaten zijn even groot, dus het budget in bytes bepaalt
        // hoeveel er in dit verzoek gelezen worden.
        $inline = min(self::INLINE_MAX_ITEMS, intdiv($this->inlineByteBudget(), $size));

        foreach ($ids->take($inline) as $id) {
            rescue(fn () => $this->hashItem((int) $id));
        }

        return $backfillPending || $ids->count() > $inline;
    }

    /**
     * Hoeveel bytes er in het verzoek van een upload samen gelezen mogen
     * worden om oudere bestanden te hashen. De rest doet de wachtrij.
     */
    protected function inlineByteBudget(): int
    {
        return 20 * 1024 * 1024;
    }

    /**
     * Zet voor de items in dit bereik de grootte van hun mediarij op het
     * item, in één query. Een item zonder mediarij krijgt 0, zodat "nog
     * geen grootte" alleen betekent dat deze stap er nog niet langs is geweest.
     */
    public function fillSizes(int $fromId, int $toId): int
    {
        $types = implode(', ', array_map(fn (string $type) => DB::getPdo()->quote($type), [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS]));

        return DB::table('filament_media_library')
            ->whereBetween('id', [$fromId, $toId])
            ->whereNull('size')
            ->update(['size' => DB::raw(
                "(select coalesce(min(media.size), 0) from media where media.model_id = filament_media_library.id and media.model_type in ({$types}) and media.collection_name = " . DB::getPdo()->quote(MediaItem::COLLECTION) . ')'
            )]);
    }

    /**
     * De volgende items zonder checksum, met hun grootte. Zonder $all alleen
     * kandidaten: een item waarvan de grootte bij geen ander item voorkomt
     * kan geen duplicaat zijn en hoeft niet gelezen te worden.
     *
     * @return array<int, int> grootte per item-id, oplopend op id
     */
    public function unhashedSizes(int $afterId, int $limit, bool $all = false): array
    {
        return $this->unhashedQuery($all)
            ->where('item.id', '>', $afterId)
            ->orderBy('item.id')
            ->limit($limit)
            ->pluck('item.size', 'item.id')
            ->map(fn ($size) => (int) $size)
            ->all();
    }

    /**
     * Hoeveel bestanden er nog te hashen zijn en hoeveel bytes dat lezen is.
     * Telt alleen items waarvan de grootte al bekend is.
     *
     * @return array{files: int, bytes: int}
     */
    public function unhashedTotals(bool $all = false): array
    {
        $row = $this->unhashedQuery($all)->selectRaw('count(*) as files, coalesce(sum(item.size), 0) as bytes')->first();

        return ['files' => (int) ($row->files ?? 0), 'bytes' => (int) ($row->bytes ?? 0)];
    }

    public function withoutSize(): int
    {
        return DB::table('filament_media_library')->whereNull('size')->count();
    }

    private function unhashedQuery(bool $all)
    {
        $query = DB::table('filament_media_library as item')
            ->whereNull('item.checksum')
            ->whereNotNull('item.size')
            ->where('item.size', '>', 0);

        if (! $all) {
            $query->whereExists(fn ($other) => $other
                ->from('filament_media_library as other')
                ->whereColumn('other.size', 'item.size')
                ->whereColumn('other.id', '<>', 'item.id'));
        }

        return $query;
    }

    public function maxId(): int
    {
        return (int) DB::table('filament_media_library')->max('id');
    }

    private function mediaQuery()
    {
        return Media::query()
            ->whereIn('model_type', [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS])
            ->where('collection_name', MediaItem::COLLECTION);
    }
}
