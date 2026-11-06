<?php

namespace Dashed\DashedFiles\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ClearTempImages extends Command
{
    public $signature = 'dashed:clear-temp-images';

    public $description = 'Clear temp images';

    /**
     * Een conversie die nu draait werkt in zo'n map; wat jonger is dan dit
     * blijft dus staan.
     */
    private const MAX_AGE_HOURS = 6;

    public static function hasWork(): bool
    {
        return rescue(fn () => self::expiredEntries() !== [], true, false);
    }

    public function handle(): int
    {
        foreach (self::expiredEntries() as $path) {
            is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
        }

        $this->info('Temp images cleared');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private static function expiredEntries(): array
    {
        // Dezelfde map als Spatie\MediaLibrary\Support\TemporaryDirectory.
        $directory = config('media-library.temporary_directory_path') ?? storage_path('media-library/temp');

        if (! is_dir($directory)) {
            return [];
        }

        $limit = now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();
        $expired = [];

        foreach (new FilesystemIterator($directory) as $entry) {
            // Een conversie kan de map net zelf hebben opgeruimd.
            $modified = @filemtime($entry->getPathname());

            if ($modified !== false && $modified <= $limit) {
                $expired[] = $entry->getPathname();
            }
        }

        return $expired;
    }
}
