<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;

class CreateConversionUrls extends Command
{
    public $signature = 'dashed:create-conversion-urls';

    public $description = 'Create conversion urls';

    public function handle(): int
    {
        $chunkSize = 25;

        $this->info('Media conversion cache builder');
        $this->line('Chunk size: ' . $chunkSize);

        $query = MediaLibraryItem::query();

        $total = $query->count();

        if ($total === 0) {
            $this->info('No media items found to process.');

            return self::SUCCESS;
        }

        $this->info("Processing {$total} media items...");
        $bar = $this->output->createProgressBar($total);

        $errors = 0;

        $query
            ->orderBy('id')
            ->chunkById($chunkSize, function ($items) use ($bar, &$errors) {
                foreach ($items as $item) {
                    if ($item->conversions) {
                        foreach (json_decode($item->conversions, true) as $conversion) {
                            try {
                                // Dit triggert jouw hele logica:
                                // - JSON conversion_urls bijwerken
                                // - Laravel cache vullen
                                mediaHelper()->getSingleMedia($item->id, $conversion);
                            } catch (\Throwable $e) {
                                $errors++;
                                $this->error("Error on media #{$item->id} ({$conversion}): {$e->getMessage()}");
                                // Optioneel: log naar laravel.log
                                report($e);
                            }
                        }
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->info('Done building media conversion cache.');
        if ($errors > 0) {
            $this->warn("Completed with {$errors} errors (check logs).");
        }

        return self::SUCCESS;
    }
}
