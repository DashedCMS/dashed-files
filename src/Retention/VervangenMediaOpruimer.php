<?php

declare(strict_types=1);

namespace Dashed\DashedFiles\Retention;

use Carbon\Carbon;
use Dashed\DashedCore\Retention\Termijn;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedCore\Retention\Contracts\Opruimer;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Ruimt het vorige bestand van een vervangen item op. Dat zijn mediarijen
 * (collectie `library-replaced`) en geen modellen in de prullenbak. Via het
 * model van Spatie en niet met een kale databaseverwijdering: alleen het
 * model haalt het bestand en zijn formaten van de schijf.
 */
class VervangenMediaOpruimer implements Opruimer
{
    // Een nieuw bestand dat tijdens het vervangen is blijven hangen
    // (`library-incoming`, het proces viel weg voor de wissel) gaat na een dag weg.
    private const INCOMING_DAYS = 1;

    public function ruimOp(Termijn $termijn, int $portie, bool $droog): int
    {
        $kolom = $termijn->datumkolom();

        $query = fn () => Media::query()
            ->whereIn('model_type', [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS])
            ->where(fn ($query) => $query
                ->where(fn ($replaced) => $replaced
                    ->where('collection_name', MediaItem::COLLECTION_REPLACED)
                    ->where($kolom, '<', Carbon::now()->subDays($termijn->dagen())))
                ->orWhere(fn ($incoming) => $incoming
                    ->where('collection_name', MediaItem::COLLECTION_INCOMING)
                    ->where($kolom, '<', Carbon::now()->subDays(self::INCOMING_DAYS))));

        if ($droog) {
            return $query()->count();
        }

        $aantal = 0;

        $query()->chunkById(max(1, $portie), function ($rows) use (&$aantal) {
            foreach ($rows as $media) {
                $media->delete();
                $aantal++;
            }
        });

        return $aantal;
    }
}
