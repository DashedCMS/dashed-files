<?php

namespace Dashed\DashedFiles\Listeners;

use Illuminate\Support\Facades\Cache;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Jobs\FlushMediaDependentCaches;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

class ListenForNewConversions
{
    public function handle(ConversionHasBeenCompletedEvent $event): void
    {
        $item = MediaItem::fromMedia($event->media);

        if (! $item) {
            return;
        }

        // Voorheen liep dit over de waarden van generated_conversions
        // (booleans) en vergat het daardoor een sleutel die niet bestond.
        // Tijdens een slag van de pomp niet per formaat: die leegt de cache één keer per item.
        if (! \Dashed\DashedFiles\Classes\Conversions\ConversionPump::isHandling($item->getKey())) {
            app(MediaLibrary::class)->invalidate($item, 'conversion-completed');
        }

        // Na een vervanging is de pagina mogelijk al opnieuw gecachet met de
        // terugval-url. Zodra ALLE formaten van het nieuwe bestand er zijn nog
        // één keer legen: één ronde per vervanging, niet één per formaat. Een
        // formaat dat al tijdens het bewaren klaar was (nog in
        // `library-incoming`) is er voor de eerste ronde al.
        $media = $event->media;

        if ($media->collection_name !== MediaItem::COLLECTION || ! $media->getCustomProperty('replacement_of')) {
            return;
        }

        $expected = (array) rescue(fn () => $media->getMediaConversionNames(), [], false);
        $ready = array_keys(array_filter((array) $media->generated_conversions));

        if (array_diff($expected, $ready) !== []) {
            return;
        }

        if (Cache::add('media-library-replaced-flushed-' . $media->getKey(), true, 3600)) {
            FlushMediaDependentCaches::queueFor((int) $item->getKey());
        }
    }
}
