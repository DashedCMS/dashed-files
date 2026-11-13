<?php

namespace Dashed\DashedFiles\Events;

use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Het bestand van een bestaand item is vervangen: het id is gelijk gebleven,
 * de url is veranderd. MediaIngested vuurt hier bewust niet.
 */
class MediaReplaced
{
    use Dispatchable;

    public function __construct(
        public readonly MediaItem $item,
        public readonly int $oldMediaId,
        public readonly int $newMediaId,
    ) {
    }
}
