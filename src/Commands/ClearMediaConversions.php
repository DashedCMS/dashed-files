<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Dashed\DashedFiles\Classes\MediaLibrary;

class ClearMediaConversions extends Command
{
    public $signature = 'dashed:clear-media-conversions';

    public $description = 'Clear media conversions';

    public function handle(): int
    {
        // Ook de cache: daar staan dezelfde url's in, en zonder dit
        // leest de site ze daar gewoon weer uit.
        app(MediaLibrary::class)->invalidateAll();

        return self::SUCCESS;
    }
}
