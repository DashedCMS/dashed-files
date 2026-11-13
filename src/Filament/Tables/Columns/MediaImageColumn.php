<?php

namespace Dashed\DashedFiles\Filament\Tables\Columns;

use Throwable;
use Illuminate\Support\Arr;
use Filament\Tables\Columns\ImageColumn;

/**
 * Toont een kolom met een media-id (of een lijst id's) als afbeelding uit de
 * mediabibliotheek.
 */
class MediaImageColumn extends ImageColumn
{
    protected string|array $mediaConversion = 'thumb';

    protected ?int $preloadedFor = null;

    public function conversion(string|array $conversion): static
    {
        $this->mediaConversion = $conversion;
        $this->preloadedFor = null;

        return $this;
    }

    public function getState(): mixed
    {
        $ids = static::mediaIds(parent::getState());

        if ($ids === []) {
            return null;
        }

        $this->preloadVisibleRows();

        $urls = array_values(array_filter(array_map(fn (int|string $id): ?string => $this->urlFor($id), $ids)));

        return match (count($urls)) {
            0 => null,
            1 => $urls[0],
            default => $urls,
        };
    }

    /**
     * @return array<int, int|string>
     */
    public static function mediaIds(mixed $state): array
    {
        return array_values(array_filter(
            Arr::flatten(Arr::wrap($state)),
            fn (mixed $id): bool => (is_int($id) && $id > 0) || (is_string($id) && trim($id) !== ''),
        ));
    }

    protected function urlFor(int|string $id): ?string
    {
        $media = mediaHelper()->getSingleMedia($id, $this->mediaConversion);

        // getSingleMedia() geeft '' bij niets, een geplakte URL ongewijzigd
        // terug en anders een object met een url.
        $url = is_string($media) ? $media : data_get($media, 'url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Laadt de url's van alle zichtbare rijen in één keer, zodat de kolom
     * niet per rij een eigen cache-opvraag doet.
     */
    protected function preloadVisibleRows(): void
    {
        $current = $this->getRecord();

        try {
            $records = $this->getTable()->getRecords();

            // De tabel geeft per pagina hetzelfde object terug, dus dit loopt één keer per pagina.
            if ($this->preloadedFor === spl_object_id($records)) {
                return;
            }

            $this->preloadedFor = spl_object_id($records);
            $ids = [];

            foreach ($records as $record) {
                $this->record($record);

                foreach (static::mediaIds(parent::getState()) as $id) {
                    if (is_int($id) || ctype_digit($id)) {
                        $ids[(int) $id] = (int) $id;
                    }
                }
            }

            if ($ids !== []) {
                mediaHelper()->preloadMediaUrls(array_values($ids), $this->mediaConversion);
            }
        } catch (Throwable) {
            // Voorladen is alleen een versnelling: zonder haalt elke rij zijn eigen url op.
        } finally {
            if ($current !== null) {
                $this->record($current);
            }
        }
    }
}
