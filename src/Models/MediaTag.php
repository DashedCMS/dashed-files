<?php

namespace Dashed\DashedFiles\Models;

use Illuminate\Database\Eloquent\Model;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MediaTag extends Model
{
    public const MAX_LENGTH = 50;

    protected $table = BrowseSchema::TAGS;

    protected $fillable = [
        'name',
    ];

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(MediaItem::class, BrowseSchema::ITEM_TAG, 'media_tag_id', 'media_item_id');
    }
}
