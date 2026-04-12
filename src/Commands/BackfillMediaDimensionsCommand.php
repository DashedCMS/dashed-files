<?php

namespace Dashed\DashedFiles\Commands;

use Dashed\DashedFiles\Jobs\BackfillMediaDimensionsJob;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class BackfillMediaDimensionsCommand extends Command
{
    protected $signature = 'dashed:backfill-media-dimensions {--chunk=50 : Items per job} {--queue=default : Queue name}';

    protected $description = 'Dispatch jobs to backfill original_width/height for all image media without dimensions';

    public function handle(): int
    {
        $remaining = Media::query()
            ->where('mime_type', 'like', 'image/%')
            ->where('mime_type', 'not like', '%svg%')
            ->where(function ($q) {
                $q->whereNull('custom_properties->original_width')
                    ->orWhere('custom_properties->original_width', '');
            })
            ->count();

        if ($remaining === 0) {
            $this->info('All media items already have dimensions.');

            return self::SUCCESS;
        }

        $chunkSize = (int) $this->option('chunk');
        $queue = $this->option('queue');

        BackfillMediaDimensionsJob::dispatch(0, $chunkSize)->onQueue($queue);

        $this->info("Dispatched backfill job for {$remaining} media items (chunks of {$chunkSize} on queue '{$queue}'). Each chunk auto-dispatches the next until done.");

        return self::SUCCESS;
    }
}
