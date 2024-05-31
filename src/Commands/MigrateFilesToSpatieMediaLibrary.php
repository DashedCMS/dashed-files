<?php

namespace Dashed\DashedFiles\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MigrateFilesToSpatieMediaLibrary extends Command
{
    public $signature = 'dashed:migrate-files-to-spatie-media-library';

    public $description = 'Migrate files from dashed to spatie media library';

    public function handle(): int
    {
        MediaLibraryFolder::all()->each(fn($folder) => $folder->delete());
        MediaLibraryItem::all()->each(fn($item) => $item->delete());
        Media::all()->each(fn($media) => $media->delete());

        $folders = Storage::disk('dashed')->allDirectories();
        $user = User::first();

        foreach ($folders as $folder) {
            if (!str($folder)->contains('__media-cache')) {
                $this->info('Migration started for folder: ' . $folder);

                $parentId = $this->getParentId($folder);

                if (MediaLibraryFolder::where('name', $folder)->exists()) {
                    $this->info('Folder already exists, skipping...');
                    continue;
                }
                $newFolder = new MediaLibraryFolder();
                $newFolder->name = $folder;
                $newFolder->parent_id = $parentId;
                $newFolder->save();

                $this->withProgressBar(Storage::disk('dashed')->files($folder), function ($file) use ($user, $newFolder) {
                    if (Storage::disk('dashed')->exists($file)) {
//                        $uploadedFile = UploadedFile::createFromBase(new \Symfony\Component\HttpFoundation\File\UploadedFile($file, basename($file)));
//                        dd($uploadedFile);

//                        MediaLibraryItem::addUpload($uploadedFile);
                        try {
                            $filamentMediaLibraryitem = new MediaLibraryItem();
                            $filamentMediaLibraryitem->uploaded_by_user_id = $user->id;
                            $filamentMediaLibraryitem->folder_id = $newFolder->id;
                            $filamentMediaLibraryitem->save();

                            $fileName = basename($file);
                            if (str($fileName)->length() > 200) {
                                $newFileName = str($fileName)->substr(50);
                                $newFile = str($file)->replace($fileName, $newFileName);
                                Storage::disk('dashed')->move($file, $newFile);
                                $file = $newFile;
                            }
                            $filamentMediaLibraryitem->addMediaFromDisk($file, 'dashed')
                                ->toMediaCollection();
                            $this->info('File migrated: ' . $file);
                        } catch (\Exception $e) {
                            $this->error('Error migrating file: ' . $file);
                            $this->error($e->getMessage());
                        }
                    }
                });
            }
        }


        foreach (MediaLibraryFolder::all() as $folder) {
            $folder->name = str($folder->name)->explode('/')->last();
            $folder->save();
        }

        return self::SUCCESS;
    }

    private function getParentId($folder): ?int
    {
        $folders = str($folder)->explode('/')->toArray();
        array_pop($folders);
        return MediaLibraryFolder::where('name', implode('/', $folders))->first()->id ?? null;
    }
}
