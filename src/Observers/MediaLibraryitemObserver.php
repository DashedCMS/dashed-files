<?php

namespace Dashed\DashedFiles\Observers;

use Illuminate\Support\Facades\Storage;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;

class MediaLibraryitemObserver
{
    public function copyDirectoryContents($source, $destination)
    {
        // Get all files from the source directory
        $files = Storage::disk('dashed')->allFiles($source);
        foreach ($files as $file) {
            $newFile = str_replace($source, $destination, $file);
//            dump($file, $newFile);
            Storage::disk('dashed')->move($file, $newFile);
        }
//        dd($files, $source, $destination);
//        dd($files);

//        // Get all directories from the source directory
//        $directories = Storage::disk('dashed')->allDirectories($source);
//        foreach ($directories as $directory) {
//            $newDirectory = str_replace($source, $destination, $directory);
//            Storage::disk('dashed')->makeDirectory($newDirectory);
//            $this->copyDirectoryContents($directory, $newDirectory); // Recursively copy subdirectories
//        }
    }

    public function updated(MediaLibraryItem $mediaLibraryItem)
    {
        $oldFolderId = $mediaLibraryItem->getOriginal()['folder_id'];
        $newFolderId = $mediaLibraryItem->folder_id;

        if ($oldFolderId !== $newFolderId) {
            $name = $mediaLibraryItem->getItem()->name;
            $sourceDirectory = trim(rtrim(mediaHelper()->getFolderPath($oldFolderId) . '/' . $name, '/'), '/');
            $destinationDirectory = trim(rtrim(mediaHelper()->getFolderPath($newFolderId) . '/' . $name, '/'), '/');
            dump($sourceDirectory, $destinationDirectory);
            if (Storage::disk('dashed')->exists($sourceDirectory)) {
                // Ensure the destination directory exists
                if (!Storage::disk('dashed')->exists($destinationDirectory)) {
                    Storage::disk('dashed')->makeDirectory($destinationDirectory);
                }

                // Copy the directory contents
                $this->copyDirectoryContents($sourceDirectory, $destinationDirectory);

                dump('Directory contents copied successfully!');
            } else {
                dump("Source directory does not exist.");
            }
        }
    }
}
