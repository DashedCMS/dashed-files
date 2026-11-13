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
     * Een conversie of een inname die nu draait werkt in zo'n map; wat jonger
     * is dan dit blijft dus staan.
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
        return array_merge(
            // Dezelfde map als Spatie\MediaLibrary\Support\TemporaryDirectory.
            self::expiredIn(config('media-library.temporary_directory_path') ?? storage_path('media-library/temp')),
            // Waar de inname en de downloader hun tijdelijke bestand zetten. Een
            // proces dat tussen ophalen en innemen sneuvelt laat het daar staan.
            self::expiredIn(storage_path('app/media-ingest')),
        );
    }

    /**
     * @return array<int, string>
     */
    private static function expiredIn(string $directory): array
    {
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
