<?php

namespace Dashed\DashedFiles\Observers;

use Illuminate\Support\Facades\Cache;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaObserver
{
    public function updated(Media $media)
    {
        $filamentMedia = MediaLibraryItem::find($media->model_id);
        foreach (json_decode($filamentMedia->conversions ?: '{}', true) as $conversion) {
            Cache::forget('media-library-media-' . $filamentMedia->id . '-' . mediaHelper()->getConversionName($conversion));
        }
    }
}
