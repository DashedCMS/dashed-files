<?php

namespace Dashed\DashedFiles\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Dashed\DashedFiles\Models\AiImageOperation;

class AiImageOperationCompleted
{
    use Dispatchable;

    public function __construct(public AiImageOperation $operation)
    {
    }
}
