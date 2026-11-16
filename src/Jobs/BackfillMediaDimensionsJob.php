<?php

namespace Dashed\DashedFiles\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedCore\Models\BackgroundTask;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Observers\MediaObserver;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class BackfillMediaDimensionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const TASK_TYPE = 'media-dimensions';

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

        $query = Media::query()
            ->where('mime_type', 'like', 'image/%')
            ->where('mime_type', 'not like', '%svg%')
            ->where(function ($q) {
                $q->whereNull('custom_properties->original_width')
                    ->orWhere('custom_properties->original_width', '');
            });

        $items = (clone $query)
            ->orderBy('id')
            ->take($this->chunkSize)
            ->get();

        // Eén taak in het takenicoon van het CMS voor de hele keten: elke
        // portie is een eigen job en pakt de lopende taak weer op.
        $task = $this->task(fn (): int => $items->isEmpty() ? 0 : (clone $query)->count());

        if ($items->isEmpty()) {
            $task?->finish(__(':aantal afbeeldingen verwerkt.', ['aantal' => (int) $task->done]));

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

                // Alleen voor een mediarij van de bibliotheek zelf: model_id van
                // een ander model wijst naar een willekeurig item.
                if ($item = MediaItem::fromMedia($media)) {
                    app(MediaLibrary::class)->invalidate($item, 'dimensions');
                    // Stil opgeslagen: de observer die de spiegelkolommen bijhoudt ziet dit niet.
                    app(MediaLibrary::class)->syncMirror($item);
                }
            } catch (\Throwable $e) {
                $media->setCustomProperty('original_width', 0);
                $media->setCustomProperty('original_height', 0);
                $media->saveQuietly();
            }
        }

        $task?->advance($items->count());

        // Dispatch next chunk - offset stays 0 because processed items fall out of the query
        self::dispatch(0, $this->chunkSize)
            ->onQueue($this->queue ?? 'default')
            ->delay(now()->addSeconds(5));
    }

    /**
     * Een afgebroken portie zet de keten stil; de taak mag dan niet als
     * lopend blijven staan.
     */
    public function failed(?\Throwable $e = null): void
    {
        if (class_exists(BackgroundTask::class)) {
            rescue(fn () => $this->activeTask()?->fail($e), report: false);
        }
    }

    /**
     * De lopende taak van deze keten, of een nieuwe bij de eerste portie.
     * Zonder gebruiker en zonder melding: de keten start vanuit het commando.
     */
    protected function task(\Closure $remaining): ?BackgroundTask
    {
        if (! class_exists(BackgroundTask::class)) {
            return null;
        }

        return rescue(function () use ($remaining): ?BackgroundTask {
            if ($task = $this->activeTask()) {
                return $task;
            }

            // Het aantal dat nog moet, alleen geteld bij de eerste portie.
            $total = $remaining();

            return $total > 0
                ? BackgroundTask::begin(self::TASK_TYPE, __('Afmetingen van afbeeldingen aanvullen'), $total, notify: false)
                : null;
        }, null, report: false);
    }

    protected function activeTask(): ?BackgroundTask
    {
        return BackgroundTask::query()
            ->where('type', self::TASK_TYPE)
            ->active()
            ->latest('id')
            ->first();
    }
}
