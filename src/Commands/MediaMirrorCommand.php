<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Dashed\DashedFiles\Jobs\BackfillMediaMirror;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Browse\MediaFolderTree;

class MediaMirrorCommand extends Command
{
    protected $signature = 'dashed:media-mirror {--paths : Also rebuild the path of every folder}';

    protected $description = 'Fill file name, type, size and dimensions on media library items, so the library can search and filter without a join';

    public function handle(MediaFolderTree $folders): int
    {
        if (! BrowseSchema::hasMirror()) {
            $this->error('The mirror columns are missing. Run the migrations first.');

            return self::FAILURE;
        }

        if ($this->option('paths')) {
            $this->info('Folder paths rebuilt: ' . $folders->rebuildPaths() . ' changed.');
        }

        BackfillMediaMirror::dispatch();

        $this->info('Queued: the mirror columns for every item.');

        return self::SUCCESS;
    }
}
