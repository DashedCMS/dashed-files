<?php

namespace Dashed\DashedFiles\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedFiles\Models\MediaConversionRun;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Conversions\ConversionPump;

/**
 * Eén slag van de pomp: verwerkt binnen een tijdsbudget een paar formaten
 * van een run en zet daarna de volgende slag klaar. Er loopt dus één keten
 * per run door de wachtrij, die gedeeld wordt met de ordermails van de webshop.
 *
 * Elke slag draagt een kenmerk. Alleen de taak met het kenmerk dat op de run
 * staat (`owner`) mag hem verder zetten: wordt er een nieuwe slag klaargezet
 * (hervatten, de minuuttaak), dan stopt een oudere taak die nog in de
 * wachtrij stond zodra hij aan de beurt komt. Zo kan er nooit een tweede
 * keten naast de eerste gaan lopen.
 *
 * Op een synchrone wachtrij doet een aanroep precies één slag; de minuuttaak
 * (`dashed:media-conversions tick`) zet de run daarna verder. Anders zou de
 * hele bibliotheek in het verzoek van de beheerder of binnen schedule:run
 * worden afgewerkt.
 */
class RegenerateMediaBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    // Ruim boven het tijdsbudget van de pomp (25 s) plus één traag formaat,
    // en onder de gebruikelijke retry_after van 90 s.
    public $timeout = 80;

    public $tries = 1;

    // Tussen twee slagen: de wachtrij krijgt lucht voor ander werk.
    public const PAUSE_SECONDS = 3;

    public function __construct(public int $runId, public ?string $token = null)
    {
    }

    public function handle(ConversionPump $pump): void
    {
        if (! BrowseSchema::hasRuns()) {
            return;
        }

        $run = MediaConversionRun::query()->find($this->runId);

        // Een oudere keten: er is intussen een nieuwere slag klaargezet.
        if (! $run || ! $run->isActive() || ($this->token !== null && $run->owner !== $this->token)) {
            return;
        }

        // Twee werkers op dezelfde run doen dubbel werk; het slot is de laatste rem.
        $lock = Cache::lock('media-conversion-run-' . $this->runId, 90);

        if (! $lock->get()) {
            return;
        }

        try {
            $more = $pump->process($run, null, $this->token);
        } catch (Throwable $exception) {
            // Eén melding voor de run, niet een per item.
            report($exception);
            $pump->markFailed($this->runId, $exception->getMessage());

            return;
        } finally {
            rescue(fn () => $lock->release(), report: false);
        }

        // Op een synchrone wachtrij zou een nieuwe slag hier direct en
        // genest draaien, tot alles klaar is: daar doet de minuuttaak het.
        if ($more && ! ConversionPump::queueIsSync()) {
            $pump->dispatch($run, self::PAUSE_SECONDS);
        }
    }

    /** Een harde fout (time-out, werker gestopt): de run blijft staan en is te hervatten. */
    public function failed(?Throwable $exception): void
    {
        rescue(fn () => app(ConversionPump::class)->markFailed($this->runId, $exception?->getMessage() ?: 'The worker stopped.'), report: false);
    }
}
