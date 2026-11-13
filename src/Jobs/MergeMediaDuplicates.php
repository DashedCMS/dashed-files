<?php

namespace Dashed\DashedFiles\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Dashed\DashedFiles\Classes\Queue\WorkBudget;
use Dashed\DashedFiles\Classes\Usage\MediaDuplicates;

/**
 * Een samenvoeging die meer modellen raakt dan in één verzoek past. Meldt
 * de uitkomst in het paneel aan wie hem startte.
 *
 * In slagen binnen de standaard retry_after van 90 seconden: elke slag zet
 * modellen om tot het werkbudget op is en zet dan de volgende klaar, vanaf
 * het laatst behandelde model. Pas de laatste slag ruimt de duplicaten op
 * en stuurt de melding.
 */
class MergeMediaDuplicates implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = WorkBudget::MAX_JOB_TIMEOUT;

    public int $uniqueFor = 3600;

    /**
     * @param  array<int, int>  $otherIds
     */
    public function __construct(
        public int $keepId,
        public array $otherIds,
        public string $batch,
        public ?int $userId = null,
        public ?array $after = null,
        public int $converted = 0,
        public int $step = 0,
    ) {
        if (filled($queue = config('dashed-files.ingest.queue'))) {
            $this->onQueue((string) $queue);
        }
    }

    // Met de slag erin: deze taak zet zijn opvolger klaar terwijl zijn eigen slot nog vastzit.
    public function uniqueId(): string
    {
        return $this->step === 0 ? $this->batch : $this->batch . ':' . $this->step;
    }

    public function handle(MediaDuplicates $duplicates): void
    {
        $keep = MediaItem::query()->find($this->keepId);

        if (! $keep) {
            $this->notify(__('Samenvoegen is niet gelukt: het bestand dat moest blijven bestaat niet meer.'), false);

            return;
        }

        $budget = WorkBudget::start();

        try {
            $step = $duplicates->rewriteStep($keep, $this->otherIds, $this->batch, $this->userId, $this->after, fn () => $budget->spent());

            if (! $step['done']) {
                dispatch((new self($this->keepId, $this->otherIds, $this->batch, $this->userId, $step['after'], $this->converted + $step['converted'], $this->step + 1))
                    ->onConnection($this->connection)->onQueue($this->queue));

                return;
            }

            $result = $duplicates->finish($keep, $this->otherIds, $this->batch, $this->userId, $this->converted + $step['converted']);
        } catch (Throwable $exception) {
            report($exception);
            $this->notify(__('Samenvoegen is niet gelukt. Er is niets weggegooid dat nog in gebruik was.'), false);

            return;
        }

        $message = __('Samengevoegd: :count verwijzingen omgezet.', ['count' => $result['converted']]);

        if ($result['kept_out'] !== []) {
            $message .= ' ' . __(':count bestanden zijn niet weggegooid omdat een verwijzing niet zeker om te zetten was.', ['count' => count($result['kept_out'])]);
        }

        $this->notify($message, $result['kept_out'] === []);
    }

    private function notify(string $message, bool $success): void
    {
        $model = config('auth.providers.users.model');
        $user = $this->userId !== null && is_string($model) && class_exists($model) ? $model::query()->find($this->userId) : null;

        if (! $user || ! class_exists(\Filament\Notifications\Notification::class)) {
            return;
        }

        rescue(function () use ($user, $message, $success) {
            $notification = \Filament\Notifications\Notification::make()->title(__('Dubbele bestanden samenvoegen'))->body($message);

            ($success ? $notification->success() : $notification->warning())->sendToDatabase($user);
        });
    }
}
