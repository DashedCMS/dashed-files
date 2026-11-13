<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Dashed\DashedFiles\Classes\Preview\MediaPreviews;
use Dashed\DashedFiles\Jobs\GenerateMediaPreviewBacklog;

/**
 * Zet voor bestaande video's en pdf's de voorvertoning en de metadata
 * klaar. Bewust alleen op commando: bij de uitrol gebeurt er niets vanzelf.
 */
class MediaPreviewsCommand extends Command
{
    public const MAX_IDS = 500;

    public $signature = 'dashed:media-previews
        {--missing : Every video and pdf that has not been looked at yet}
        {--retry : With --missing: also files that were looked at before and got no preview, while the server can now make one}
        {--ids= : Comma separated item ids, also when they already have a preview}';

    public $description = 'Queue posters, thumbnails and metadata for existing videos and pdfs, one file at a time';

    public function handle(MediaPreviews $previews): int
    {
        if (! MediaPreviews::available()) {
            $this->error('The own media engine is not active or the preview columns do not exist yet (run the migrations).');

            return self::FAILURE;
        }

        $ids = $this->ids();

        if ($ids === null && ! $this->option('missing')) {
            $this->error('Pass --missing or --ids=1,2,3.');

            return self::FAILURE;
        }

        if ($ids !== null) {
            // Alleen wat bestaat en een video of pdf is.
            $ids = $previews->kindQuery()->whereIn('filament_media_library.id', $ids)->orderBy('filament_media_library.id')->pluck('filament_media_library.id')->map(fn ($id) => (int) $id)->all();
        }

        $retry = (bool) $this->option('retry');
        $count = $ids !== null ? count($ids) : $previews->missingQuery($retry)->count();

        $this->line(sprintf(
            '%d file(s) to look at. Video posters: %s. Pdf thumbnails: %s.',
            $count,
            $previews->generatorFor('video/mp4') ? 'yes (ffmpeg)' : 'no, only duration and size from the mp4 header',
            $previews->generatorFor('application/pdf') ? 'yes (ghostscript)' : 'no, only the page count where readable',
        ));
        $this->line('A file from the chat or the api, or without a known source, never goes to an external program: it only gets what php reads itself.');

        if ($count === 0) {
            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! $this->confirm('Start?', true)) {
            return self::SUCCESS;
        }

        // Zonder werker is er geen wachtrij om te sparen: dan hier, één voor één.
        if (MediaPreviews::queueIsSync()) {
            $done = 0;
            $after = 0;

            while ($done < 100000) {
                $id = $ids !== null
                    ? ($ids[$done] ?? null)
                    : $previews->missingQuery($retry)->where('filament_media_library.id', '>', $after)->orderBy('filament_media_library.id')->value('filament_media_library.id');

                if ($id === null) {
                    break;
                }

                $result = $previews->generate((int) $id);
                $after = (int) $id;
                $done++;

                $this->line(sprintf('  #%d: %s', $id, json_encode($result, JSON_UNESCAPED_SLASHES)));
            }

            $this->info($done . ' file(s) done.');

            return self::SUCCESS;
        }

        GenerateMediaPreviewBacklog::dispatch($ids, 0, $retry);

        $this->info('Queued: one job at a time works through the list.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>|null
     */
    private function ids(): ?array
    {
        $option = $this->option('ids');

        if (! is_string($option) || trim($option) === '') {
            return null;
        }

        $ids = array_values(array_unique(array_filter(array_map(fn (string $id) => ctype_digit(trim($id)) ? (int) trim($id) : 0, explode(',', $option)))));

        return array_slice($ids, 0, self::MAX_IDS);
    }
}
