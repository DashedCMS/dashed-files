<?php

namespace Dashed\DashedFiles\MediaLibrary\Concerns;

trait HasPreviewConversionsConfiguration
{
    protected string $thumbnailMediaConversion = 'thumb';

    protected string $componentsMediaConversion = 'responsive';

    protected string $mediaPickerMediaConversion = 'thumb';

    protected array $firstAvailableUrlConversions = [800, 400, 'thumb'];

    /**
     * You can change the media conversion that is used to display the previews in the library.
     * The default conversion is `thumb`. This conversion is a square conversion and generated
     * automatically already. However, you can change the conversion to any other conversion.
     * Please keep in mind that square conversions that aren't large work best.
     */
    public function thumbnailMediaConversion(string $conversion): static
    {
        $this->thumbnailMediaConversion = $conversion;

        return $this;
    }

    public function componentsMediaConversion(string $conversion): static
    {
        $this->componentsMediaConversion = $conversion;

        return $this;
    }

    public function mediaPickerMediaConversion(string $conversion): static
    {
        $this->mediaPickerMediaConversion = $conversion;

        return $this;
    }

    public function firstAvailableUrlConversions(array $conversions): static
    {
        $this->firstAvailableUrlConversions = $conversions;

        return $this;
    }

    public function getThumbnailMediaConversion(): string
    {
        return $this->thumbnailMediaConversion;
    }

    public function getComponentsImageConversion(): string
    {
        return $this->componentsMediaConversion;
    }

    public function getMediaPickerMediaConversion(): string
    {
        return $this->mediaPickerMediaConversion;
    }

    public function getFirstAvailableUrlConversions(): array
    {
        return $this->firstAvailableUrlConversions;
    }
}
