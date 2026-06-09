<?php

namespace Dashed\DashedFiles\Models;

use Illuminate\Database\Eloquent\Model;

class AiImageOperation extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public const TYPE_REMOVE_BACKGROUND = 'remove_background';
    public const TYPE_UPSCALE = 'upscale';
    public const TYPE_EDIT = 'edit';
    public const TYPE_PRODUCT_PHOTO = 'product_photo';
    public const TYPE_GENERATE = 'generate';

    protected $table = 'ai_image_operations';

    protected $guarded = [];

    protected $casts = [
        'params' => 'array',
    ];

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }
}
