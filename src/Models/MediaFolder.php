<?php

namespace Dashed\DashedFiles\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Dashed\DashedEcommerceCore\Classes\ShoppingCart;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MediaFolder extends Model
{

    protected $table = 'dashed__media_folders';

    public static function booted()
    {
        static::saved(function ($folder) {
            foreach (MediaFolder::all() as $otherFolder) {
                $otherFolder->path = $otherFolder->getPath();
                $otherFolder->saveQuietly();
            }
        });
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
