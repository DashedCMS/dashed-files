<?php

namespace Dashed\DashedFiles\Commands;

use Dashed\DashedFiles\Observers\MediaObserver;
use Illuminate\Console\Command;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class BackfillMediaDimensionsCommand extends Command
{
    protected $signature = 'dashed:backfill-media-dimensions {--force : Overwrite existing dimensions}';

    protected $description = 'Backfill original_width and original_height for all existing image media';

    public function handle(): int
    {
        $force = $this->option('force');
        $observer = new MediaObserver();

        $query = Media::query()
            ->where('mime_type', 'like', 'image/%')
            ->where('mime_type', 'not like', '%svg%');

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('custom_properties->original_width')
                    ->orWhere('custom_properties->original_width', '');
            });
        }

        $total = $query->count();
        $this->info("Processing {$total} media items...");

        $bar = $this->output->createProgressBar($total);
        $filled = 0;
        $skipped = 0;
        $failed = 0;

        $query->chunkById(100, function ($items) use ($observer, &$filled, &$skipped, &$failed, $bar) {
            foreach ($items as $media) {
                try {
                    $dimensions = $observer->getImageDimensions($media);

                    if (! $dimensions) {
                        $skipped++;
                        $bar->advance();

                        continue;
                    }

                    $media->setCustomProperty('original_width', $dimensions[0]);
                    $media->setCustomProperty('original_height', $dimensions[1]);
                    $media->saveQuietly();

                    // Clear the conversion_urls cache on the related MediaLibraryItem
                    // so the next resolve picks up the new dimensions
                    MediaLibraryItem::where('id', $media->model_id)
                        ->update(['conversion_urls' => null]);

                    $filled++;
                } catch (\Throwable $e) {
                    $failed++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done: {$filled} filled, {$skipped} skipped, {$failed} failed");

        return self::SUCCESS;
    }
}
