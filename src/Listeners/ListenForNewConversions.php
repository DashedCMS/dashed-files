<?php

namespace Dashed\DashedFiles\Listeners;

use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

class ListenForNewConversions
{
    /**
     * Handle the event.
     */
    public function handle(ConversionHasBeenCompletedEvent $event): void
    {
        foreach ($event->media->generated_conversions as $conversionName) {
            $cacheTag = 'media-library-media-' . $event->media->model_id . '-' . $conversionName;
            Cache::forget($cacheTag);
        }
    }
}
