<?php

namespace Dashed\DashedFiles\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedFiles\Observers\MediaObserver;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;

class BackfillMediaDimensionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $offset = 0,
        public int $chunkSize = 50,
    ) {
    }

    public function handle(): void
    {
        $observer = new MediaObserver();

        $items = Media::query()
            ->where('mime_type', 'like', 'image/%')
            ->where('mime_type', 'not like', '%svg%')
            ->where(function ($q) {
                $q->whereNull('custom_properties->original_width')
                    ->orWhere('custom_properties->original_width', '');
            })
            ->orderBy('id')
            ->take($this->chunkSize)
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        foreach ($items as $media) {
            try {
                $dimensions = $observer->getImageDimensions($media);
                if (! $dimensions) {
                    $media->setCustomProperty('original_width', 0);
                    $media->setCustomProperty('original_height', 0);
                    $media->saveQuietly();

                    continue;
                }

                $media->setCustomProperty('original_width', $dimensions[0]);
                $media->setCustomProperty('original_height', $dimensions[1]);
                $media->saveQuietly();

                MediaLibraryItem::where('id', $media->model_id)
                    ->update(['conversion_urls' => null]);
            } catch (\Throwable $e) {
                $media->setCustomProperty('original_width', 0);
                $media->setCustomProperty('original_height', 0);
                $media->saveQuietly();
            }
        }

        // Dispatch next chunk - offset stays 0 because processed items fall out of the query
        self::dispatch(0, $this->chunkSize)
            ->onQueue($this->queue ?? 'default')
            ->delay(now()->addSeconds(5));
    }
}
