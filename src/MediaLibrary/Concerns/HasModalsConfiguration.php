<?php

namespace Dashed\DashedFiles\MediaLibrary\Concerns;

trait HasModalsConfiguration
{
    protected string $mediaPickerModalWidth = '4xl';

    public function mediaPickerModalWidth(string $width = '7xl'): static
    {
        $this->mediaPickerModalWidth = $width;

        return $this;
    }

    public function getMediaPickerModalWidth(): string
    {
        return $this->mediaPickerModalWidth;
    }
}
