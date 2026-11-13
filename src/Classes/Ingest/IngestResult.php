<?php

namespace Dashed\DashedFiles\Classes\Ingest;

use Dashed\DashedFiles\Models\MediaItem;

final class IngestResult
{
    public function __construct(
        public readonly MediaItem $item,
        public readonly bool $wasDuplicate,
        public readonly string $checksum,
        // Het bestaande item lag in de prullenbak en is teruggezet.
        public readonly bool $wasRestored = false,
    ) {
    }
}
