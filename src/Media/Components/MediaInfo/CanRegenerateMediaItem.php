<?php

namespace Dashed\DashedFiles\Media\Components\MediaInfo;

use RalphJSmit\Filament\MediaLibrary\Media\Components\MediaInfo;
use Spatie\MediaLibrary\Conversions\FileManipulator;

/**
 * @mixin MediaInfo
 */
trait CanRegenerateMediaItem
{
    public bool $regenerationRequested = false;

    public function regenerateMediaItem(): void
    {
        if ($this->mediaItemId) {
            $fileManipulator = app(FileManipulator::class);

            $fileManipulator->createDerivedFiles(
                media: $this->getMediaProperty()->getItem(),
                withResponsiveImages: true
            );
        }

        $this->regenerationRequested = true;

        $this->dispatch('$refresh');
    }
}
