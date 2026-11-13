<?php

namespace Dashed\DashedFiles\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\InteractsWithQueue;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedFiles\Classes\Queue\WorkBudget;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Access\MediaRelocator;
use Dashed\DashedFiles\Classes\Access\MediaVisibility;

/**
 * Verhuist de bestanden van items die van kant wisselden (een map werd privé
 * of publiek, een item ging een privé-map in of uit). In porties: één slag
 * werkt binnen een tijdsbudget een aantal items af en zet daarna de volgende
 * klaar, vanaf het item waar hij bleef. De taak draagt zelf geen lijst: wat
 * er te doen is staat op de items (`relocate_to`). Een item dat mislukt
 * blijft zo staan en komt bij een volgende ronde opnieuw aan de beurt, tot
 * het ingestelde aantal pogingen op is: dan slaat de taak het over tot
 * iemand het opnieuw laat proberen (force).
 *
 * Het budget wordt ook tussen de bestanden van één item getoetst, dus een
 * groot item gaat over meerdere slagen. De time-out (85 s) past binnen de
 * standaard retry_after van 90 seconden: een lopende slag wordt dus niet
 * door een tweede werker opgepakt. Na het budget (40 s) begint de slag geen
 * nieuw bestand meer, en één bestand is niet te onderbreken: daarom kopieert
 * de taak geen bestand boven `dashed-files.private.queue_max_mb` (standaard
 * 100 MB, in 45 s is dat 2,5 MB/s). Zo'n item blijft wachten; de doctor
 * meldt het en `dashed:media-private relocate --now` verhuist het buiten de
 * wachtrij, zonder tijdslimiet.
 */
class RelocateMediaItems implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const BATCH = 25;

    public const BUDGET_SECONDS = 40;

    private const LOCK = 'media-relocate-running';

    public $timeout = WorkBudget::MAX_JOB_TIMEOUT;

    public $tries = 1;

    // Een item dat mislukte wacht een kwartier voor de volgende poging.
    public const RETRY_AFTER_SECONDS = 900;

    public function __construct(public int $afterId = 0, public bool $force = false)
    {
        if (filled($queue = config('dashed-files.ingest.queue'))) {
            $this->onQueue((string) $queue);
        }
    }

    /** Zet een ronde klaar. Op een synchrone wachtrij loopt die meteen, in dit verzoek. */
    public static function start(): void
    {
        rescue(fn () => self::dispatch());
    }

    public function handle(MediaRelocator $relocator): void
    {
        if (! BrowseSchema::hasAccess()) {
            return;
        }

        // Eén keten tegelijk. Loopt er al een, dan pakt die ook wat er net bij kwam.
        $lock = Cache::lock(self::LOCK, $this->timeout + 30);

        if (! $lock->get()) {
            return;
        }

        $more = false;
        $last = $this->afterId;

        try {
            $started = microtime(true);
            $visibility = app(MediaVisibility::class);

            if ($this->force && $this->afterId === 0) {
                $visibility->retryGivenUp();
            }

            // Het vangnet: een open item in een privé-map gaat hier alsnog dicht.
            if ($this->afterId === 0) {
                rescue(fn () => $visibility->closeOpenItemsInPrivateFolders());
            }

            $ids = MediaItem::withoutGlobalScopes()->toBase()
                ->whereNotNull('relocate_to')
                ->when(BrowseSchema::hasRelocateProgress(), fn ($query) => $query->whereNull('relocate_gave_up_at'))
                ->where('id', '>', $this->afterId)
                ->orderBy('id')
                ->limit(self::BATCH + 1)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $more = count($ids) > self::BATCH;

            foreach (array_slice($ids, 0, self::BATCH) as $id) {
                if (microtime(true) - $started > self::BUDGET_SECONDS) {
                    $more = true;

                    break;
                }

                $last = $id;

                if (! $this->force && Cache::has(self::waitKey($id))) {
                    continue;
                }

                $outcome = $relocator->attempt($id, $started + self::BUDGET_SECONDS, MediaRelocator::queueMaxBytes());

                if ($outcome === MediaRelocator::FAILED) {
                    // Dit item blijft staan voor een volgende ronde; de rest gaat door.
                    rescue(fn () => Cache::put(self::waitKey($id), true, self::RETRY_AFTER_SECONDS), report: false);
                } elseif ($outcome === MediaRelocator::BUSY && microtime(true) - $started > self::BUDGET_SECONDS) {
                    // Het budget raakte midden in dit item op: de volgende slag gaat hier verder.
                    $more = true;
                    $last = $id - 1;

                    break;
                }
            }
        } finally {
            rescue(fn () => $lock->release(), report: false);
        }

        // Op een synchrone wachtrij één slag per aanroep: de minuuttaak
        // (dashed:media-private relocate) zet het werk daarna verder. Anders
        // zou de hele map in het verzoek van de beheerder worden afgewerkt.
        if ($more && config('queue.default') !== 'sync') {
            self::dispatch($last, $this->force);
        }
    }

    public static function waitKey(int $itemId): string
    {
        return 'media-relocate-wait:' . $itemId;
    }
}
