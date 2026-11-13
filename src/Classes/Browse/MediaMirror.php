<?php

namespace Dashed\DashedFiles\Classes\Browse;

use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;
use Dashed\DashedFiles\Models\MediaItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Houdt de spiegelkolommen op het item gelijk aan zijn mediarij, zodat de
 * lijst kan zoeken, filteren en sorteren zonder join. De mediarij blijft de
 * bron; hier wordt alleen overgenomen.
 */
final class MediaMirror
{
    private const MEDIA_COLUMNS = ['model_id', 'file_name', 'mime_type', 'size', 'custom_properties'];

    public function sync(MediaItem|int $item, ?Media $media = null): void
    {
        $id = $item instanceof MediaItem ? (int) $item->getKey() : $item;

        if ($id <= 0 || ! BrowseSchema::hasMirror()) {
            return;
        }

        // Alleen de attributen van de rij: bij `created` staat het bestand
        // nog niet op de schijf.
        $row = $media
            ? (object) ['file_name' => $media->file_name, 'mime_type' => $media->mime_type, 'size' => $media->size, 'custom_properties' => $media->custom_properties]
            : $this->mediaQuery()->where('model_id', $id)->orderBy('id')->first(self::MEDIA_COLUMNS);

        if (! $row) {
            return;
        }

        $attributes = self::attributesFor($row);

        // Vanuit de observer (met $media) blijft de grootte staan: een lege
        // grootte is voor de dubbelcontrole het teken dat een item van voor
        // de inname nog niet bekeken is (MediaChecksums), en dat teken mag
        // niet bij elke wijziging van een mediarij verdwijnen. ingest() zet
        // de grootte zelf, de aanvultaken vullen hem voor de rest.
        if ($media) {
            unset($attributes['size']);
        }

        $this->items()->where('id', $id)->update($attributes);
    }

    /**
     * @return array{file_name: ?string, mime_type: ?string, size: ?int, width: ?int, height: ?int, source: ?string}
     */
    public static function attributesFor(object $row): array
    {
        $properties = $row->custom_properties ?? [];

        if (is_string($properties)) {
            $properties = json_decode($properties, true);
        }

        $properties = is_array($properties) ? $properties : [];
        $source = $properties['source'] ?? null;

        return [
            'file_name' => filled($row->file_name ?? null) ? mb_substr((string) $row->file_name, 0, 255) : null,
            'mime_type' => filled($row->mime_type ?? null) ? mb_substr(mb_strtolower((string) $row->mime_type), 0, 127) : null,
            'size' => is_numeric($row->size ?? null) ? (int) $row->size : null,
            'width' => self::dimension($properties['original_width'] ?? null),
            'height' => self::dimension($properties['original_height'] ?? null),
            'source' => is_string($source) && $source !== '' ? mb_substr($source, 0, 40) : null,
        ];
    }

    public function maxId(): int
    {
        return (int) $this->items()->max('id');
    }

    /**
     * Vult de spiegelkolommen voor elk item in het bereik. Op MySQL in één
     * UPDATE ... JOIN; elders, of als die query faalt, per rij.
     */
    public function fillRange(int $from, int $to): void
    {
        if (! BrowseSchema::hasMirror()) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            try {
                $this->fillRangeWithJoin($from, $to);

                return;
            } catch (Throwable $exception) {
                // Bijvoorbeeld een mediarij met ongeldige json: dan dit bereik per rij.
                report($exception);
            }
        }

        $seen = [];

        foreach ($this->mediaQuery()->whereBetween('model_id', [$from, $to])->orderBy('id')->get(self::MEDIA_COLUMNS) as $row) {
            if (isset($seen[$row->model_id])) {
                continue;
            }

            $seen[$row->model_id] = true;
            $this->items()->where('id', $row->model_id)->update(self::attributesFor($row));
        }
    }

    private function fillRangeWithJoin(int $from, int $to): void
    {
        $prefix = DB::connection()->getTablePrefix();
        $items = '`' . $prefix . (new MediaItem())->getTable() . '`';
        $media = '`' . $prefix . (new Media())->getTable() . '`';

        $property = fn (string $name) => "JSON_UNQUOTE(JSON_EXTRACT(m.custom_properties, '$.{$name}'))";
        // Alleen een kaal positief getal: de waarde kan ontbreken, json-null of 0 ("niet te bepalen") zijn.
        $dimension = fn (string $name) => "CASE WHEN {$property($name)} REGEXP '^[1-9][0-9]{0,8}$' THEN CAST({$property($name)} AS UNSIGNED) ELSE NULL END";

        DB::update(
            "UPDATE {$items} i JOIN {$media} m ON m.model_id = i.id AND m.model_type IN (?, ?) AND m.collection_name = ? "
            . 'SET i.file_name = LEFT(m.file_name, 255), i.mime_type = LOWER(LEFT(m.mime_type, 127)), i.size = m.size, '
            . "i.width = {$dimension('original_width')}, i.height = {$dimension('original_height')}, "
            . "i.source = CASE WHEN JSON_TYPE(JSON_EXTRACT(m.custom_properties, '$.source')) = 'STRING' THEN NULLIF(LEFT({$property('source')}, 40), '') ELSE NULL END "
            . 'WHERE i.id BETWEEN ? AND ?',
            [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS, MediaItem::COLLECTION, $from, $to],
        );
    }

    private static function dimension(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    // Via de querybuilder: geen modellen, geen events en updated_at blijft staan.
    private function items(): Builder
    {
        return MediaItem::withoutGlobalScopes()->toBase();
    }

    private function mediaQuery(): Builder
    {
        return DB::table((new Media())->getTable())
            ->whereIn('model_type', [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS])
            ->where('collection_name', MediaItem::COLLECTION);
    }
}
