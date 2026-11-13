<?php

namespace Dashed\DashedFiles\Events;

use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Een nieuwe rasterafbeelding staat in de bibliotheek. Vuurt niet voor een
 * duplicaat dat op een bestaand item uitkwam.
 */
class MediaIngested
{
    use Dispatchable;

    public function __construct(
        public readonly MediaItem $item,
        public readonly string $source,
    ) {
    }
}
