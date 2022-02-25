<?php

namespace Qubiqx\QcommerceFiles\Commands;

use Illuminate\Console\Command;

class QcommerceFilesCommand extends Command
{
    public $signature = 'qcommerce-files';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
