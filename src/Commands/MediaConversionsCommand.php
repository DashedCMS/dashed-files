<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Models\MediaConversionRun;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Conversions\ConversionPump;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;

class MediaConversionsCommand extends Command
{
    public $signature = 'dashed:media-conversions
        {actie=status : status, missing, force, avif, cleanup, cancel, resume or tick}
        {--ids= : Comma separated item ids (missing, force, avif, cleanup)}
        {--names= : Comma separated format names such as fit-400-400 (force)}
        {--run= : The run to cancel or resume; default the active or the last failed one}
        {--if-needed : Only start a missing run when something is missing (for the scheduler); with AVIF on also the missing AVIF variants}
        {--all : avif: every generated format, not only the formats that are actually delivered}
        {--estimate : Only show how many items and files the run would touch}
        {--force : cleanup: do not ask for confirmation}';

    public $description = 'Generate or clean up image formats of the media library in small queued batches';

    public function handle(ConversionPump $pump): int
    {
        if (! BrowseSchema::hasRuns()) {
            $this->error('The table dashed__media_conversion_runs does not exist yet: run the migrations.');

            return self::FAILURE;
        }

        $action = (string) $this->argument('actie');
        $library = app(MediaLibrary::class);

        try {
            switch ($action) {
                case 'status':
                    return $this->status($pump);

                case 'tick':
                    // De minuuttaak: op een synchrone wachtrij één slag per
                    // aanroep, anders alleen een keten die stilviel opnieuw starten.
                    $count = $pump->tick();
                    $this->info($count === 0 ? 'Nothing to do.' : "Moved {$count} run(s) one batch forward.");

                    return self::SUCCESS;

                case ConversionPump::MISSING:
                case ConversionPump::FORCE:
                case ConversionPump::AVIF:
                case ConversionPump::CLEANUP:
                    $filters = ['ids' => $this->csv('ids'), 'names' => $this->csv('names'), 'all' => (bool) $this->option('all')];

                    if ($this->option('estimate')) {
                        $estimate = $pump->estimate($action, $filters);
                        $this->info(sprintf('A %s run would touch %d item(s) and %d file(s); %d item(s) would be skipped.', $action, $estimate['items'], $estimate['files'], $estimate['skipped']));

                        return self::SUCCESS;
                    }

                    if ($action === ConversionPump::MISSING && $this->option('if-needed')) {
                        return $this->nightly($pump, $library);
                    }

                    if ($action === ConversionPump::CLEANUP && ! $this->option('force') && $this->input->isInteractive()
                        && ! $this->confirm('This removes crops of an earlier focal point that are older than ' . (int) config('dashed-files.conversions.cleanup_after_days', 90) . ' days. An email that was already sent can still point to such a crop and will then show a broken image. Continue?')) {
                        return self::SUCCESS;
                    }

                    $run = $library->startConversionRun($action, $filters);

                    $this->info(sprintf('Run #%d (%s) queued for %d item(s).', $run->id, $run->type, $run->total));

                    return self::SUCCESS;

                case 'cancel':
                    $run = $this->option('run') ? MediaConversionRun::query()->find((int) $this->option('run')) : $pump->active();

                    if (! $run) {
                        $this->info('There is no active run.');

                        return self::SUCCESS;
                    }

                    $library->cancelConversionRun($run);
                    $this->info(sprintf('Run #%d cancelled at item %d.', $run->id, $run->cursor));

                    return self::SUCCESS;

                case 'resume':
                    $run = $this->option('run')
                        ? MediaConversionRun::query()->find((int) $this->option('run'))
                        : MediaConversionRun::query()->whereIn('status', [MediaConversionRun::FAILED, MediaConversionRun::CANCELLED])->orderByDesc('id')->first();

                    if (! $run) {
                        $this->info('There is no failed or cancelled run to resume.');

                        return self::SUCCESS;
                    }

                    $run = $library->resumeConversionRun($run);
                    $this->info(sprintf('Run #%d resumed from item %d.', $run->id, $run->cursor));

                    return self::SUCCESS;
            }
        } catch (MediaRejectedException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->error("Unknown action [{$action}]. Use status, missing, force, avif, cleanup, cancel, resume or tick.");

        return self::FAILURE;
    }

    /**
     * De nachtrun: alleen als er iets ontbreekt, en met AVIF aan ook de
     * AVIF-varianten van wat er intussen bij is gekomen.
     */
    private function nightly(ConversionPump $pump, MediaLibrary $library): int
    {
        $started = false;

        if ($pump->hasMissing(fresh: true)) {
            $run = $library->startConversionRun(ConversionPump::MISSING);
            $this->info(sprintf('Run #%d (%s) queued for %d item(s).', $run->id, $run->type, $run->total));
            $started = true;
        }

        if ($pump->hasMissingAvif() && ! $pump->active(ConversionPump::AVIF)) {
            $run = $library->startConversionRun(ConversionPump::AVIF);
            $this->info(sprintf('Run #%d (%s) queued for %d item(s).', $run->id, $run->type, $run->total));
            $started = true;
        }

        if (! $started) {
            $this->info('Nothing is missing.');
        }

        return self::SUCCESS;
    }

    private function status(ConversionPump $pump): int
    {
        $runs = MediaConversionRun::query()->orderByDesc('id')->limit(10)->get();

        if ($runs->isEmpty()) {
            $this->info('No runs yet.');
        } else {
            $this->table(
                ['#', 'type', 'status', 'done', 'total', 'failed', 'skipped', 'cursor', 'started', 'heartbeat', 'finished', 'last problem'],
                $runs->map(fn (MediaConversionRun $run) => [
                    $run->id,
                    $run->type,
                    $run->status,
                    $run->done,
                    $run->total,
                    $run->failed,
                    $run->skipped,
                    $run->cursor,
                    $run->started_at?->toDateTimeString() ?? '-',
                    $run->heartbeat_at?->toDateTimeString() ?? '-',
                    $run->finished_at?->toDateTimeString() ?? '-',
                    mb_substr((string) collect(explode("\n", (string) $run->last_error))->filter()->last(), 0, 70),
                ])->all(),
            );
        }

        $givenUp = $pump->givenUp();

        if ($givenUp !== []) {
            $this->warn(sprintf(
                'Given up after %d failed attempts (skipped by every run until the file changes): item %s. Ask again with: dashed:media-conversions force --ids=<id>',
                ConversionPump::MAX_ATTEMPTS,
                implode(', ', $givenUp),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function csv(string $option): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->option($option))), fn (string $value) => $value !== ''));
    }
}
