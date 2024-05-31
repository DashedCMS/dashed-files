<?php

namespace Dashed\DashedFiles\Commands;

use App\Models\User;
use Dashed\DashedFiles\Models\MediaFile;
use Dashed\DashedFiles\Models\MediaFolder;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;

class MigrateFilesToSpatieMediaLibrary extends Command
{
    public $signature = 'dashed:migrate-files-to-spatie-media-library';

    public $description = 'Migrate files from dashed to spatie media library';

    public function handle(): int
    {
        MediaFolder::all()->each(function ($folder) {
            $folder->delete();
        });
        MediaFile::all()->each(function ($file) {
            $file->delete();
        });

        $folders = Storage::disk('dashed')->allDirectories();
        $user = User::first();

        foreach ($folders as $folder) {
            if (!str($folder)->contains('__media-cache')) {
                $this->info('Migration started for folder: ' . $folder);

                $parentId = $this->getParentId($folder);

                if (MediaFolder::where('name', $folder)->exists()) {
                    $this->info('Folder already exists, skipping...');
                    continue;
                }
                $newFolder = new MediaFolder();
                $newFolder->name = $folder;
                $newFolder->parent_id = $parentId;
                $newFolder->save();

                $files = $this->withProgressBar(Storage::disk('dashed')->files($folder), function ($file) use ($user, $newFolder){
                    if (Storage::disk('dashed')->exists($file)) {
                        $mediaFile = new MediaFile();
                        $mediaFile->folder_id = $newFolder->id;
                        $mediaFile->uploaded_by_user_id = $user->id;
                        $mediaFile->name = str($file)->explode('/')->last();
                        $mediaFile->file_name = $file;
                        $mediaFile->mime_type = Storage::disk('dashed')->mimeType($file);
                        $mediaFile->disk = 'dashed';
                        $mediaFile->size = Storage::disk('dashed')->size($file);
                        $mediaFile->save();

                        $this->info('File migrated: ' . $file);
                    }
                });
            }
        }


        foreach (MediaFolder::all() as $folder) {
            $folder->name = str($folder->name)->explode('/')->last();
            $folder->save();
        }

        return self::SUCCESS;
    }

    private function getParentId($folder): ?int
    {
        $folders = str($folder)->explode('/')->toArray();
        array_pop($folders);
        return MediaFolder::where('name', implode('/', $folders))->first()->id ?? null;
    }
}
