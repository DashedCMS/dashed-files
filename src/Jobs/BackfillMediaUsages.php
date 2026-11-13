<?php

namespace Dashed\DashedFiles\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Dashed\DashedFiles\Classes\Queue\WorkBudget;
use Dashed\DashedFiles\Classes\Usage\MediaUsageIndexer;

/**
 * De nulmeting van de gebruiksindex voor één modelklasse. Eén taak per
 * klasse die zichzelf in porties doorschuift: een trage of grote tabel
 * houdt de andere klassen niet op, en een mislukte klasse is met
 * `queue:retry` los opnieuw te proberen.
 */
class BackfillMediaUsages implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    // Een portie is `chunk` rijen uitlezen en indexeren, zonder bestanden of
    // externe aanroepen: ruim binnen de standaard retry_after van 90 seconden.
    public int $timeout = WorkBudget::MAX_JOB_TIMEOUT;

    public int $uniqueFor = 3600;

    /**
     * @param  string|null  $since  alleen rijen die sinds dit moment zijn gewijzigd (de nachtronde); null is een volledige ronde
     */
    public function __construct(
        public string $modelClass,
        public int $afterId = 0,
        public int $chunk = 200,
        public ?string $since = null,
    ) {
        // Dezelfde wachtrij als de vingerafdrukken: een drukke webshop geeft
        // dit soort achtergrondwerk een eigen werker.
        if (filled($queue = config('dashed-files.ingest.queue'))) {
            $this->onQueue((string) $queue);
        }
    }

    // Per klasse en beginpunt: het slot van deze taak staat nog als hij de
    // volgende klaarzet, en die zou met één slot per klasse geweigerd worden.
    public function uniqueId(): string
    {
        return $this->modelClass . ':' . $this->afterId . ':' . ($this->since === null ? 'full' : 'recent');
    }

    public function handle(MediaUsageIndexer $indexer): void
    {
        $full = $this->since === null;

        if (! class_exists($this->modelClass)) {
            if ($full) {
                $indexer->classFinished($this->modelClass);
            }

            return;
        }

        $next = $indexer->reindexChunk($this->modelClass, $this->afterId, $this->chunk, $this->since);

        if ($next > 0) {
            dispatch((new self($this->modelClass, $next, $this->chunk, $this->since))->onConnection($this->connection)->onQueue($this->queue));

            return;
        }

        // Alleen een volledige ronde ruimt op en telt voor de vlag "gevuld".
        if ($full) {
            $indexer->pruneOrphans($this->modelClass);
            $indexer->classFinished($this->modelClass);
        }
    }
}
