<?php

namespace Dashed\DashedFiles\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedFiles\Classes\Preview\MediaPreviews;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * De achterstand van bestaande video's en pdf's, gedoseerd: één taak die
 * precies één bestand bekijkt en daarna zichzelf voor het volgende
 * klaarzet. Zo staat er nooit meer dan één van deze taken in de gedeelde
 * wachtrij en komt een ordermail er altijd tussendoor. Tussen twee
 * schakels zit een pauze (`dashed-files.preview.backlog_delay`), zodat de
 * werker niet aan één stuk door video's verwerkt. Alleen
 * `dashed:media-previews` start hem.
 */
class GenerateMediaPreviewBacklog implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    /**
     * @param  array<int, int>|null  $ids  een vaste lijst, of null voor "alles wat ontbreekt"
     * @param  bool  $retry  ook wat al bekeken is en toen geen voorvertoning kreeg
     */
    public function __construct(public ?array $ids = null, public int $afterId = 0, public bool $retry = false)
    {
        if (filled($queue = config('dashed-files.ingest.queue'))) {
            $this->onQueue((string) $queue);
        }
    }

    public function uniqueId(): string
    {
        return 'backlog';
    }

    public function handle(MediaPreviews $previews): void
    {
        if (! MediaPreviews::available()) {
            return;
        }

        $id = $this->currentId($previews);

        if ($id === null) {
            return;
        }

        $previews->generate($id);

        $this->next($id);
    }

    /**
     * De werker heeft de taak afgebroken (time-out): het bestand is al als
     * bekeken gemarkeerd, dus de keten gaat verder met het volgende.
     */
    public function failed(?Throwable $exception = null): void
    {
        rescue(function () {
            $id = $this->currentId(app(MediaPreviews::class));

            if ($id !== null) {
                $this->next($this->ids === null ? $this->afterId : $id);
            }
        }, report: false);
    }

    private function currentId(MediaPreviews $previews): ?int
    {
        if ($this->ids !== null) {
            return $this->ids === [] ? null : (int) array_values($this->ids)[0];
        }

        $id = $previews->missingQuery($this->retry)->where('filament_media_library.id', '>', $this->afterId)->orderBy('filament_media_library.id')->value('filament_media_library.id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function next(int $doneId): void
    {
        if ($this->ids !== null) {
            $rest = array_slice(array_values($this->ids), 1);

            if ($rest !== []) {
                self::dispatch($rest, 0, $this->retry)->delay(self::pause());
            }

            return;
        }

        self::dispatch(null, $doneId, $this->retry)->delay(self::pause());
    }

    /** De pauze tussen twee schakels, in seconden. */
    public static function pause(): int
    {
        $seconds = config('dashed-files.preview.backlog_delay', 5);

        return is_numeric($seconds) ? max(0, min(3600, (int) $seconds)) : 5;
    }
}
