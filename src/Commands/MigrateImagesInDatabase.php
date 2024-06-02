<?php

namespace Dashed\DashedFiles\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;

class MigrateImagesInDatabase extends Command
{
    public $signature = 'dashed:migrate-images-in-database';

    public $description = 'Migrate images in database';

    public $mediaLibraryItems;
    public int $failedToMigrateCount = 0;

    public function handle(): int
    {
        $mediaLibraryItems = MediaLibraryItem::all();
        foreach ($mediaLibraryItems as $mediaLibraryItem) {
            $mediaLibraryItem['file_name_to_match'] = basename($mediaLibraryItem->getItem()->getPath() ?? '');
        }
        $this->mediaLibraryItems = $mediaLibraryItems;

        $tables = DB::select('SHOW TABLES');
        $tablesToSkip = [
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

        $columnsToSkip = [
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
        $databaseName = DB::getDatabaseName();

        foreach ($tables as $table) {
            $tableName = $table->{"Tables_in_$databaseName"};
            //            if ($tableName == 'dashed__translations') {
            if (! in_array($tableName, $tablesToSkip)) {
                $this->info('Checking table: ' . $tableName);

                // Get all columns of the table
                $columns = Schema::getColumnListing($tableName);

                $this->withProgressBar($columns, function ($column) use ($tableName, $columnsToSkip) {
                    //                    if ($column == 'value') {
                    if (! in_array($column, $columnsToSkip) || str($column)->endsWith('_id')) {
                        $this->info('checking column: ' . $column . ' in table: ' . $tableName);
                        $rows = DB::table($tableName)->select('id', $column)->get();

                        $this->withProgressBar($rows, function ($row) use ($column, $tableName) {
                            $this->checkValueForImagePath($row->$column, $tableName, $column, $row->id);
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

    private function performAction($tableName, $columnName, $value, $rowId)
    {
        try {
            $fileExists = Storage::disk('dashed')->exists($value);
            if(! str($value)->contains('/')) {
                $fileExists = false;
            }
        } catch (Exception $exception) {
            $fileExists = false;
        }

        if ($fileExists) {
            $fileToCheck = basename($value);
            if (str($fileToCheck)->length() > 200) {
                $value = str(str($fileToCheck)->explode('/')->last())->substr(50);
            }
            if ($mediaItem = $this->mediaLibraryItems->where('file_name_to_match', basename($value))->first()) {
                $currentValue = DB::table($tableName)
                    ->where('id', $rowId)
                    ->select($columnName)
                    ->first();
                DB::table($tableName)
                    ->where('id', $rowId)
                    ->update([
                        $columnName => str($currentValue->$columnName)->replace($value, $mediaItem->id),
                    ]);
                //                $this->info('Replacement made in ' . $tableName . ' for ' . $columnName . ' with id ' . $rowId . ' with value ' . $value . ' with ' . $mediaItem->id);
            } else {
                $this->error('Media item not found for ' . $value . ' in ' . $tableName . ' for ' . $columnName . ' with id ' . $rowId);
                $this->failedToMigrateCount++;
            }
        }
    }
}
