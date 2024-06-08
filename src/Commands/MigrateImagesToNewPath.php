<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MigrateImagesToNewPath extends Command
{
    public $signature = 'dashed:migrate-images-to-new-path';

    public $description = 'Migrate images to new path';

    public function getOldPath(Media $media): string
    {
        $path = '/';

        if ($media->model->folder ?? false) {
            foreach ($media->model->folder->getAncestors() as $ancestor) {
                $path .= $ancestor->name . '/';
            }
        }

        return $path . basename($media->name) . '/';
    }

    public function handle(): int
    {
        MediaLibraryItem::all()->map(function ($item) {
            $mediaItem = $item->getItem();
            $oldPath = trim(rtrim($this->getOldPath($mediaItem), '/'), '/');
            $oldPathFile = $oldPath . '/' . $mediaItem->name;
            $newPath = trim(rtrim($mediaItem->getPath(), '/'), '/');
            Storage::disk('dashed')->move($oldPathFile, $newPath);
            Artisan::call('media-library:regenerate', ['--ids' => $mediaItem->id]);
            Storage::disk('dashed')->deleteDirectory($oldPath);
            $this->info("Moved " . $mediaItem->name);
        });

        return self::SUCCESS;
    }
}
