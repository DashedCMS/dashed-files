<?php

namespace Dashed\DashedFiles\Classes\Conversions;

use Dashed\DashedCore\Models\BackgroundTask;
use Dashed\DashedFiles\Models\MediaConversionRun;

/**
 * Spiegelt een run van de pomp naar het takenicoon van dashed-core. De pomp
 * schrijft de run met kale queries (geen model-events), dus de spiegel wordt
 * aangeroepen op de plekken waar de stand verandert: bij het starten,
 * annuleren, hervatten en mislukken, en na elke slag.
 *
 * Niet elke run hoort in het menu. Een run voor ontbrekende formaten die een
 * bezoeker aanvroeg (geen gebruiker) loopt elke dag vele keren, een
 * focuspunt geldt voor één afbeelding, en een run van één item is zo klaar.
 *
 * Alles hier is bijzaak: een oudere dashed-core kent het model niet en een
 * fout in de administratie mag de pomp nooit tegenhouden.
 */
class ConversionRunTask
{
    public static function sync(MediaConversionRun|int|null $run): void
    {
        if ($run === null || ! class_exists(BackgroundTask::class)) {
            return;
        }

        // Soort en starter veranderen niet: een run van een bezoeker kost zo
        // geen extra query aan het einde van zijn verzoek.
        if ($run instanceof MediaConversionRun && ! self::tracksKind($run)) {
            return;
        }

        rescue(function () use ($run): void {
            $run = MediaConversionRun::query()->find($run instanceof MediaConversionRun ? $run->getKey() : $run);

            if (! $run || ! self::tracks($run)) {
                return;
            }

            $status = match ($run->status) {
                MediaConversionRun::RUNNING => BackgroundTask::STATUS_RUNNING,
                MediaConversionRun::DONE => BackgroundTask::STATUS_COMPLETED,
                MediaConversionRun::FAILED, MediaConversionRun::CANCELLED => BackgroundTask::STATUS_FAILED,
                default => BackgroundTask::STATUS_QUEUED,
            };

            $processed = (int) $run->done + (int) $run->failed + (int) $run->skipped;

            BackgroundTask::mirror($run, [
                'type' => 'media-conversions',
                'label' => self::label($run->type),
                'user_id' => $run->user_id,
                // Wie zelf annuleert hoeft daar geen melding van.
                'notify' => $run->user_id !== null && $run->status !== MediaConversionRun::CANCELLED,
                'status' => $status,
                'done' => $run->total ? min($processed, (int) $run->total) : $processed,
                'total' => $run->total ?: null,
                'message' => match ($run->status) {
                    MediaConversionRun::DONE => $run->failed > 0 || $run->skipped > 0
                        ? __(':gelukt gelukt, :mislukt mislukt, :overgeslagen overgeslagen.', ['gelukt' => (int) $run->done, 'mislukt' => (int) $run->failed, 'overgeslagen' => (int) $run->skipped])
                        : __(':aantal afbeeldingen verwerkt.', ['aantal' => (int) $run->done]),
                    MediaConversionRun::CANCELLED => __('Geannuleerd.'),
                    MediaConversionRun::FAILED => mb_substr((string) $run->last_error, 0, 500) ?: null,
                    default => null,
                },
                // Een hervatte run is weer bezig.
                'finished_at' => $status === BackgroundTask::STATUS_COMPLETED || $status === BackgroundTask::STATUS_FAILED ? ($run->finished_at ?? now()) : null,
            ]);
        }, report: false);
    }

    public static function tracks(MediaConversionRun $run): bool
    {
        return self::tracksKind($run) && (int) $run->total > 1;
    }

    private static function tracksKind(MediaConversionRun $run): bool
    {
        if ($run->type === ConversionPump::FOCAL) {
            return false;
        }

        return ! ($run->type === ConversionPump::MISSING && $run->user_id === null);
    }

    protected static function label(?string $type): string
    {
        return match ($type) {
            ConversionPump::FORCE => __('Formaten opnieuw genereren'),
            ConversionPump::AVIF => __('AVIF-varianten genereren'),
            ConversionPump::CLEANUP => __('Oude formaatbestanden opruimen'),
            default => __('Ontbrekende formaten genereren'),
        };
    }
}
