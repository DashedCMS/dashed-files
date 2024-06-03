<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;

class MigrateImagesInDatabase extends Command
{
    public $signature = 'dashed:migrate-images-in-database';

    public $description = 'Migrate images in database';

    public $mediaLibraryItems;
    public int $failedToMigrateCount = 0;

    private function getTablesToSkip(): array
    {
        return [
            'activity_log',
            'dashed__url_history',
            'dashed__media_files',
            'dashed__media_folders',
            'media',
            'filament_media_library',
            'filament_media_library_folders',
            'migrations',
            'password_resets',
            'users',
            'seo_scores',
            'seo_scans',
            'personal_access_tokens',
            'failed_jobs',
            'jobs',
            'sessions',
            'telescope_entries',
            'telescope_entries_tags',
            'dashed__not_found_pages',
            'dashed__not_found_page_occurrences',
        ];
    }

    private function getColumnsToSkip(): array
    {
        return [
            'id',
            'name',
            'slug',
            'created_at',
            'updated_at',
            'deleted_at',
            'ip',
            'like',
            'url',
            'color',
            'site_ids',
            'start_date',
            'end_date',
            'parent_id',
            'user_agent',
            'from_url',
            'locale',
            'viewed',
        ];
    }

    public function handle(): int
    {

        $this->mediaLibraryItems = MediaLibraryItem::all()->map(function ($item) {
            $item['file_name_to_match'] = basename($item->getItem()->getPath() ?? '');

            return $item;
        });

        $tables = DB::select('SHOW TABLES');
        $tablesToSkip = $this->getTablesToSkip();
        $columnsToSkip = $this->getColumnsToSkip();
        $databaseName = DB::getDatabaseName();

        foreach ($tables as $table) {
            $tableName = $table->{"Tables_in_$databaseName"};
            if (! in_array($tableName, $tablesToSkip)) {
                $this->info('Checking table: ' . $tableName);

                // Get all columns of the table
                $columns = Schema::getColumnListing($tableName);

                $this->withProgressBar($columns, function ($column) use ($tableName, $columnsToSkip) {
                    if (! in_array($column, $columnsToSkip) || str($column)->endsWith('_id')) {
                        $this->info('checking column: ' . $column . ' in table: ' . $tableName);
                        DB::table($tableName)->select('id', $column)->orderBy('id')->chunk(100, function ($rows) use ($column, $tableName) {
                            foreach ($rows as $row) {
                                $this->checkValueForImagePath($row->$column, $tableName, $column, $row->id);
                            }
                        });
                    }
                });
            }
        }

        if ($this->failedToMigrateCount > 0) {
            $this->error('Failed to migrate count: ' . $this->failedToMigrateCount);
        } else {
            $this->info('All images migrated successfully');
        }

        return self::SUCCESS;
    }

    private function checkValueForImagePath($value, $tableName, $columnName, $rowId)
    {
        if (is_string($value)) {
            if ($this->isJson($value)) {
                $decodedValue = json_decode($value, true);
                $this->checkValueForImagePath($decodedValue, $tableName, $columnName, $rowId);
            } else {
                $this->performAction($tableName, $columnName, $value, $rowId);
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $this->checkValueForImagePath($item, $tableName, $columnName, $rowId);
            }
        }
    }

    private function isJson($string)
    {
        json_decode($string);

        return (json_last_error() == JSON_ERROR_NONE);
    }

    private function isLikelyFilePath($string): bool
    {
        $fileExtensions = [
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'webp', 'svg', 'ico', 'heic', 'heif', 'raw', 'psd', 'ai', 'eps', 'pdf',
        ];

        return (Str::contains($string, '/') || Str::contains($string, '\\')) && Str::endsWith(Str::lower($string), $fileExtensions);
    }

    private function performAction($tableName, $columnName, $value, $rowId)
    {
        if ($this->isLikelyFilePath($value) && Storage::disk('dashed')->exists($value)) {
            $fileToCheck = basename($value);
            if (str($fileToCheck)->length() > 200) {
                $value = str($fileToCheck)->substr(50);
            }
            $mediaItem = $this->mediaLibraryItems->firstWhere('file_name_to_match', basename($value));
            if ($mediaItem) {
                $currentValue = DB::table($tableName)->where('id', $rowId)->value($columnName);
                DB::table($tableName)->where('id', $rowId)->update([
                    $columnName => str($currentValue)->replace($value, $mediaItem->id),
                ]);
            } else {
                $this->logMigrationFailure($value, $tableName, $columnName, $rowId);
            }
        }
    }

    private function logMigrationFailure($value, $tableName, $columnName, $rowId)
    {
        $this->error('Media item not found for ' . $value . ' in ' . $tableName . ' for ' . $columnName . ' with id ' . $rowId);
        $this->failedToMigrateCount++;
    }
}
