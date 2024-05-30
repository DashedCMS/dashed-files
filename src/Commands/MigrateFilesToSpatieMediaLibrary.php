<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateFilesToSpatieMediaLibrary extends Command
{
    public $signature = 'dashed:migrate-files-to-spatie-media-library';

    public $description = 'My command';

    public function handle(): int
    {
        $folders = Storage::disk('dashed')->allDirectories();

        foreach($folders as $folder){
            $this->info('Migration started for folder: ' . $folder);
        }

        return self::SUCCESS;
    }
}
