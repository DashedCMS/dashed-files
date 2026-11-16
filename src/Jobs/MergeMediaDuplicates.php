<?php

namespace Dashed\DashedFiles\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedCore\Models\BackgroundTask;
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

    public const TASK_TYPE = 'media-merge';

    public function handle(MediaDuplicates $duplicates): void
    {
        $keep = MediaItem::query()->find($this->keepId);

        if (! $keep) {
            $this->notify(__('Samenvoegen is niet gelukt: het bestand dat moest blijven bestaat niet meer.'), false, true);

            return;
        }

        // Meteen in het takenicoon, ook als de eerste slag de laatste is.
        $task = $this->task();
        $budget = WorkBudget::start();

        try {
            $step = $duplicates->rewriteStep($keep, $this->otherIds, $this->batch, $this->userId, $this->after, fn () => $budget->spent());

            if (! $step['done']) {
                $task?->progress($this->converted + $step['converted']);

                dispatch((new self($this->keepId, $this->otherIds, $this->batch, $this->userId, $step['after'], $this->converted + $step['converted'], $this->step + 1))
                    ->onConnection($this->connection)->onQueue($this->queue));

                return;
            }

            $result = $duplicates->finish($keep, $this->otherIds, $this->batch, $this->userId, $this->converted + $step['converted']);
        } catch (Throwable $exception) {
            report($exception);
            $this->notify(__('Samenvoegen is niet gelukt. Er is niets weggegooid dat nog in gebruik was.'), false, true);

            return;
        }

        $message = __('Samengevoegd: :count verwijzingen omgezet.', ['count' => $result['converted']]);

        if ($result['kept_out'] !== []) {
            $message .= ' ' . __(':count bestanden zijn niet weggegooid omdat een verwijzing niet zeker om te zetten was.', ['count' => count($result['kept_out'])]);
        }

        $this->notify($message, $result['kept_out'] === []);
    }

    /** Een harde fout (time-out, werker gestopt): de taak mag niet als lopend blijven staan. */
    public function failed(?Throwable $exception = null): void
    {
        $this->notify(__('Samenvoegen is niet gelukt. Er is niets weggegooid dat nog in gebruik was.'), false, true);
    }

    /**
     * Met een dashed-core die het takenicoon kent komt de uitkomst uit de
     * taak (één melding in de bel); anders de eigen melding zoals voorheen.
     */
    private function notify(string $message, bool $success, bool $failed = false): void
    {
        if ($task = $this->task()) {
            // Al afgerond (failed() na een afgeronde slag): geen tweede melding.
            if (! $task->isFinished()) {
                $failed ? $task->fail($message) : $task->finish($message);
            }

            return;
        }

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

    /**
     * De taak van deze samenvoeging in het takenicoon van dashed-core, één
     * per batch: elke slag is een nieuwe job en pakt hem weer op.
     */
    private function task(): ?BackgroundTask
    {
        if (! class_exists(BackgroundTask::class)) {
            return null;
        }

        return rescue(function (): ?BackgroundTask {
            $task = BackgroundTask::query()->firstOrNew([
                'subject_type' => self::TASK_TYPE,
                'subject_id' => $this->batch,
            ]);

            if (! $task->exists) {
                $task->fill([
                    'type' => self::TASK_TYPE,
                    'label' => __('Dubbele bestanden samenvoegen'),
                    'user_id' => $this->userId,
                    'status' => BackgroundTask::STATUS_RUNNING,
                    'done' => 0,
                    'notify' => $this->userId !== null,
                    'started_at' => now(),
                ])->save();
            }

            return $task;
        }, null, report: false);
    }
}
