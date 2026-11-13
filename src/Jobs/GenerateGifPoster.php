<?php

namespace Dashed\DashedFiles\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedFiles\Classes\Preview\GifPoster;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * Maakt de statische poster van één geanimeerde gif voor het beheer. Eén
 * korte taak per bestand, uniek per item tot hij begint, net als de
 * voorvertoning van een video.
 */
class GenerateGifPoster implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public int $itemId)
    {
        if (filled($queue = config('dashed-files.ingest.queue'))) {
            $this->onQueue((string) $queue);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->itemId;
    }

    public function handle(GifPoster $poster): void
    {
        $poster->generate($this->itemId);
    }
}
