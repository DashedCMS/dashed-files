<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;

class ClearMediaConversions extends Command
{
    public $signature = 'dashed:clear-media-conversions';

    public $description = 'Clear media conversions';

    public function handle(): int
    {
        foreach (MediaLibraryItem::all() as $item) {
            $item->conversion_urls = [];
            $item->save();
        }

        return self::SUCCESS;
    }
}
