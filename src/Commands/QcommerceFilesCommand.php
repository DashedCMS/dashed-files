<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;

class DashedFilesCommand extends Command
{
    public $signature = 'dashed-files';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
