<?php

namespace Dashed\DashedFiles\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Dashed\DashedFiles\Classes\Queue\WorkBudget;
use Dashed\DashedFiles\Classes\Browse\MediaMirror;

/**
 * Vult de spiegelkolommen (bestandsnaam, type, grootte, afmetingen, bron)
 * van items die van voor die kolommen zijn. Eén taak die zichzelf
 * doorschuift, een bereik van id's per taak.
 */
class BackfillMediaMirror implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    // Eén bereik is één UPDATE ... JOIN (elders hooguit duizend kleine
    // updates): ruim binnen de standaard retry_after van 90 seconden.
    public int $timeout = WorkBudget::MAX_JOB_TIMEOUT;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $afterId = 0,
        public int $range = 1000,
    ) {
    }

    // Per bereik en niet één slot voor de hele keten: het slot van deze taak
    // staat nog als hij de volgende klaarzet, en die zou anders geweigerd worden.
    public function uniqueId(): string
    {
        return (string) $this->afterId;
    }

    public function handle(MediaMirror $mirror): void
    {
        $range = max(1, $this->range);
        $to = $this->afterId + $range;

        $mirror->fillRange($this->afterId + 1, $to);

        if ($to < $mirror->maxId()) {
            dispatch((new self($to, $range))->onConnection($this->connection)->onQueue($this->queue));
        }
    }
}
