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

    public function handle(): int
    {
        $tables = DB::select('SHOW TABLES');
        $tablesToSkip = [
            'activity_log',
        ];
        $databaseName = DB::getDatabaseName();

        foreach ($tables as $table) {
            $tableName = $table->{"Tables_in_$databaseName"};
            if (! in_array($tableName, $tablesToSkip)) {
                $this->info('Checking table: ' . $tableName);

                // Get all columns of the table
                $columns = Schema::getColumnListing($tableName);

                $this->withProgressBar($columns, function ($column) use ($tableName) {
                    // Get all rows for the current column
                    $rows = DB::table($tableName)->select($column)->get();

                    $this->withProgressBar($rows, function ($row) use ($column, $tableName) {
                        $value = $row->$column;
                        $this->checkValueForImagePath($value, $tableName, $column);
                    });
                });
            }
        }

        return self::SUCCESS;
    }

    private function checkValueForImagePath($value, $tableName, $columnName)
    {
        if (is_string($value)) {
            if ($this->isJson($value)) {
                $decodedValue = json_decode($value, true);
                $this->checkValueForImagePath($decodedValue, $tableName, $columnName);
            } else {
                $this->performAction($tableName, $columnName, $value);
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $this->checkValueForImagePath($item, $tableName, $columnName);
            }
        }
    }

    private function isJson($string)
    {
        json_decode($string);

        return (json_last_error() == JSON_ERROR_NONE);
    }

    private function performAction($tableName, $columnName, $value)
    {
        try {
            $fileExists = Storage::disk('dashed')->exists($value);
        } catch (Exception $exception) {
            $fileExists = false;
        }

        if ($fileExists) {
            foreach(MediaLibraryItem::all() as $mediaLibraryItem) {
                dd($mediaLibraryItem->getItem()->getUrl(), $mediaLibraryItem->getItem()->getPath());
            }
            //            $mediaLibraryItem = MediaLibraryItem::find($id);
            //            $url = $mediaLibraryItem->getItem()->getUrl()
            //            $path = $mediaLibraryItem->getItem()->getPath();
            dd($tableName, $columnName, $value);
            //Match the value with a file in the storage
        }
    }
}
