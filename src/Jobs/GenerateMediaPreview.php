<?php

namespace Dashed\DashedFiles\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedFiles\Classes\Preview\MediaPreviews;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * Bekijkt één video of pdf: duur, afmetingen of aantal pagina's, en een
 * poster of miniatuur als de server die kan maken. Eén korte taak per
 * bestand, want de wachtrij wordt gedeeld met ordermails.
 *
 * Uniek per item tot de taak begint: een tweede aanvraag terwijl er al een
 * klaarstaat doet niets, een aanvraag tijdens het werk (het bestand is net
 * vervangen) komt er wel bij. De taak schrijft zijn uitkomst alleen als het
 * item dan nog hetzelfde bestand heeft.
 *
 * Met $probeOnly start er geen extern programma en wordt het bestand niet
 * opgehaald: zo wordt de taak klaargezet als er geen werker is (synchrone
 * wachtrij) en hij dus in het webproces zou draaien.
 */
class GenerateMediaPreview implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    // Ruim onder een gebruikelijke retry_after van 90 seconden.
    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public int $itemId, public bool $probeOnly = false)
    {
        if (filled($queue = config('dashed-files.ingest.queue'))) {
            $this->onQueue((string) $queue);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->itemId;
    }

    public function handle(MediaPreviews $previews): void
    {
        $previews->generate($this->itemId, ! $this->probeOnly);
    }
}
