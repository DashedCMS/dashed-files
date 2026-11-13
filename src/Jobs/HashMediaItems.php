<?php

namespace Dashed\DashedFiles\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedFiles\Classes\Ingest\MediaChecksums;

/**
 * Vult grootte en checksum van items die van voor de inname zijn. Eén taak
 * die zichzelf doorschuift: eerst de groottes (één query per bereik), dan de
 * bestanden die gehasht moeten worden, een portie per taak.
 */
class HashMediaItems implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const PHASE_SIZES = 'sizes';

    public const PHASE_HASHES = 'hashes';

    public const DEFAULT_MAX_BYTES = 64 * 1024 * 1024;

    private const SIZE_RANGE = 1000;

    // Ook een portie vol kleine bestanden is een verzoek per bestand aan de schijf.
    private const MAX_FILES = 100;

    public int $tries = 1;

    // Ruim onder een gebruikelijke retry_after van 90 seconden: een taak die
    // daar overheen gaat wordt een tweede keer gestart terwijl de eerste nog
    // loopt. De portie in bytes hoort daarbij.
    public int $timeout = 60;

    public function __construct(
        public bool $all = false,
        public string $phase = self::PHASE_SIZES,
        public int $afterId = 0,
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
        // Leeg is de standaardwachtrij: een project zonder extra werker hoeft
        // niets te doen, een drukke webshop kan dit werk afzonderen.
        if (filled($queue = config('dashed-files.ingest.queue'))) {
            $this->onQueue((string) $queue);
        }
    }

    public function handle(MediaChecksums $checksums): void
    {
        if (! $checksums->available()) {
            return;
        }

        if ($this->phase === self::PHASE_SIZES) {
            $checksums->fillSizes($this->afterId + 1, $this->afterId + self::SIZE_RANGE);

            $next = $this->afterId + self::SIZE_RANGE;

            // Pas als elke grootte er staat is te zien welke items een
            // grootte delen, dus het hashen begint weer vooraan.
            $next < $checksums->maxId()
                ? $this->next(self::PHASE_SIZES, $next)
                : $this->next(self::PHASE_HASHES, 0);

            return;
        }

        $sizes = $checksums->unhashedSizes($this->afterId, self::MAX_FILES, $this->all);
        $budget = $this->maxBytes;
        $last = null;

        $tooLarge = self::queueMaxBytes();

        foreach ($sizes as $id => $size) {
            // Eén bestand is niet in stukken te hashen: wat groter is dan de
            // grens haalt de time-out niet zeker en blijft hier over. De
            // doctor telt ze; dashed:media-checksums --now doet ze buiten de wachtrij.
            if ($tooLarge > 0 && $size > $tooLarge) {
                $last = $id;

                continue;
            }

            // Altijd minstens één bestand, ook als dat alleen al groter is dan de portie.
            if ($last !== null && $size > $budget) {
                break;
            }

            // Eén onleesbaar bestand mag de keten niet stil laten stoppen;
            // de fout wordt gemeld en het item houdt zijn lege checksum.
            rescue(fn () => $checksums->hashItem($id));

            $budget -= $size;
            $last = $id;
        }

        // Op het id verder en niet "tot er niets meer is": een item waarvan
        // het bestand ontbreekt houdt zijn lege checksum en zou anders
        // elke ronde opnieuw vooraan staan.
        if ($last !== null && ($last !== array_key_last($sizes) || count($sizes) === self::MAX_FILES)) {
            $this->next(self::PHASE_HASHES, $last);
        }
    }

    /**
     * Het grootste bestand dat de wachtrijtaak nog hasht, in bytes. 0 = geen grens.
     */
    public static function queueMaxBytes(): int
    {
        $mb = config('dashed-files.ingest.queue_hash_max_mb', 250);

        return is_numeric($mb) ? max(0, (int) $mb) * 1024 * 1024 : 250 * 1024 * 1024;
    }

    private function next(string $phase, int $afterId): void
    {
        $job = new self($this->all, $phase, $afterId, $this->maxBytes);

        // De verbinding en de wachtrij waarop deze taak zelf draait, zodat een
        // keten die met de hand ergens is neergezet daar ook blijft.
        dispatch($job->onConnection($this->connection)->onQueue($this->queue ?? $job->queue));
    }
}
