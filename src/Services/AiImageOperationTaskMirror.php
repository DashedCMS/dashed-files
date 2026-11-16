<?php

namespace Dashed\DashedFiles\Services;

use Dashed\DashedCore\Models\BackgroundTask;
use Dashed\DashedFiles\Models\AiImageOperation;

/**
 * Spiegelt AI-beeldbewerkingen naar het takenicoon van dashed-core. Een losse
 * bewerking is één taak; een bulkactie (zelfde batch_id) is één taak voor de
 * hele reeks, met het aantal afgeronde bewerkingen als voortgang. Aangeroepen
 * vanuit AiImageOperation::booted(). Achter class_exists, want een oudere
 * dashed-core kent BackgroundTask nog niet.
 */
class AiImageOperationTaskMirror
{
    public const TYPE = 'ai-image';
    public const BATCH_SUBJECT = 'ai-image-batch';

    public static function sync(AiImageOperation $op): void
    {
        if (! class_exists(BackgroundTask::class)) {
            return;
        }

        // A row of AiSpend::charge() only books a paid call against the daily
        // ceiling; the caller (a social post image, for instance) shows its
        // own task, so mirroring it would put the same work in the list twice.
        if ((($op->context ?? [])['origin'] ?? null) === AiSpend::ORIGIN_CHARGE) {
            return;
        }

        rescue(function () use ($op): void {
            $op->batch_id ? self::syncBatch($op) : self::syncSingle($op);
        }, report: false);
    }

    /**
     * Vóór het aanmaken van een reeks: de taak met het hele aantal. Anders
     * telt de taak de bewerkingen terwijl ze nog worden aangemaakt, en is
     * hij bij een snelle werker (of een synchrone wachtrij) na elke
     * bewerking even "klaar", met een melding per keer.
     */
    public static function expect(string $batchId, int $total, ?string $type = null): void
    {
        if ($total < 1 || ! class_exists(BackgroundTask::class)) {
            return;
        }

        rescue(function () use ($batchId, $total, $type): void {
            $task = BackgroundTask::query()->firstOrNew([
                'subject_type' => self::BATCH_SUBJECT,
                'subject_id' => $batchId,
            ]);

            $task->fill(self::starter() + [
                'type' => self::TYPE,
                'label' => __(':bewerking: :aantal afbeeldingen', ['bewerking' => self::label($type), 'aantal' => $total]),
                'status' => BackgroundTask::STATUS_QUEUED,
                'done' => 0,
                'total' => $total,
            ]);
            $task->started_at ??= now();
            $task->save();
        }, report: false);
    }

    protected static function syncSingle(AiImageOperation $op): void
    {
        // Meteen mislukt aangemaakt (een beschermde afbeelding): dat staat al
        // in beeld, een taak met een melding in de bel voegt niets toe.
        if ($op->wasRecentlyCreated && $op->isFinished() && ! $op->wasChanged()) {
            return;
        }

        $status = match ($op->status) {
            AiImageOperation::STATUS_DONE => BackgroundTask::STATUS_COMPLETED,
            AiImageOperation::STATUS_FAILED => BackgroundTask::STATUS_FAILED,
            AiImageOperation::STATUS_PROCESSING => BackgroundTask::STATUS_RUNNING,
            default => BackgroundTask::STATUS_QUEUED,
        };

        BackgroundTask::mirror($op, self::starter() + [
            'type' => self::TYPE,
            'label' => self::label($op->type),
            'status' => $status,
            'message' => match ($status) {
                BackgroundTask::STATUS_FAILED => $op->error,
                BackgroundTask::STATUS_COMPLETED => __('Het beeld staat in de bibliotheek.'),
                default => null,
            },
        ]);
    }

    /**
     * Elke bewerking van de reeks is een eigen job, mogelijk op meerdere
     * workers tegelijk. De stand komt daarom telkens uit de database, en de
     * claim op status voorkomt dat twee workers de taak allebei afsluiten en
     * de beheerder twee meldingen krijgt.
     */
    protected static function syncBatch(AiImageOperation $op): void
    {
        // Eén query voor de stand van de hele reeks.
        $counts = AiImageOperation::query()
            ->where('batch_id', $op->batch_id)
            ->selectRaw('status, count(*) as aantal')
            ->groupBy('status')
            ->pluck('aantal', 'status');

        $failed = (int) ($counts[AiImageOperation::STATUS_FAILED] ?? 0);
        $done = (int) ($counts[AiImageOperation::STATUS_DONE] ?? 0);

        $task = BackgroundTask::query()->firstOrNew([
            'subject_type' => self::BATCH_SUBJECT,
            'subject_id' => (string) $op->batch_id,
        ]);

        // Het aantal uit expect() telt mee zolang nog niet alles is aangemaakt.
        $total = max((int) $counts->sum(), (int) $task->total);

        // Al afgesloten en niets nieuws meer te doen: laat hem met rust.
        if ($task->exists && $task->isFinished() && $done + $failed >= $total) {
            return;
        }

        $task->fill(self::starter() + [
            'type' => self::TYPE,
            'label' => __(':bewerking: :aantal afbeeldingen', ['bewerking' => self::label($op->type), 'aantal' => $total]),
            'status' => BackgroundTask::STATUS_RUNNING,
            'done' => $done + $failed,
            'total' => $total,
            'finished_at' => null,
        ]);
        $task->started_at ??= now();
        $task->save();

        if ($done + $failed < $total) {
            return;
        }

        $claimed = BackgroundTask::query()
            ->whereKey($task->getKey())
            ->active()
            ->update(['status' => BackgroundTask::STATUS_COMPLETED]);

        if ($claimed !== 1) {
            return;
        }

        $message = __(':gelukt gelukt, :mislukt mislukt.', ['gelukt' => $done, 'mislukt' => $failed]);

        $done === 0 ? $task->fail($message) : $task->finish($message);
    }

    /**
     * De beheerder die de bewerking startte. Alleen bekend in het verzoek
     * waarin de bewerking wordt aangemaakt; in de wachtrij is er geen
     * ingelogde gebruiker en blijft staan wat er al stond. De jobs sturen
     * zelf geen melding, dus met een bekende gebruiker komt die uit de taak.
     *
     * @return array<string, mixed>
     */
    protected static function starter(): array
    {
        $userId = rescue(fn () => auth()->id(), null, report: false);

        return $userId ? ['user_id' => $userId, 'notify' => true] : [];
    }

    protected static function label(?string $type): string
    {
        return match ($type) {
            AiImageOperation::TYPE_REMOVE_BACKGROUND => __('AI: achtergrond verwijderen'),
            AiImageOperation::TYPE_UPSCALE => __('AI: upscalen'),
            AiImageOperation::TYPE_EDIT => __('AI: retoucheren'),
            AiImageOperation::TYPE_PRODUCT_PHOTO => __('AI: productfoto maken'),
            AiImageOperation::TYPE_GENERATE => __('AI: afbeelding genereren'),
            default => __('AI: afbeelding bewerken'),
        };
    }
}
