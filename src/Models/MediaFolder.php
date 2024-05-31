<?php

namespace Dashed\DashedFiles\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;

class MediaFolder extends Model
{
    protected $table = 'dashed__media_folders';

    public static function booted()
    {
        static::deleting(function (self $folder): void {
            $folder->children()->lazy()->each(function (self $folder) {
                $folder->delete();
            });

            $folder->mediaLibraryItems()->update([
                'folder_id' => $folder->parent_id,
            ]);
        });

        static::saved(function ($folder) {
            foreach (MediaFolder::all() as $otherFolder) {
                $otherFolder->path = $otherFolder->getPath();
                $otherFolder->saveQuietly();
            }
        });
    }

    public function deleteRecursive(): void
    {
        $this->children()->lazy()->each(function (self $mediaLibraryFolder) {
            $mediaLibraryFolder->deleteRecursive();
        });

        $this->mediaLibraryItems()->lazy()->each(function (MediaLibraryItem $mediaLibraryItem) {
            $mediaLibraryItem->delete();
        });

        $this->delete();
    }

    public function getPath()
    {
        $path = $this->name;
        $folder = $this;

        while ($folder->parent) {
            $folder = $folder->parent;
            $path = $folder->name . '/' . $path;
        }

        return $path;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MediaFolder::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(MediaFile::class, 'folder_id');
    }
}
