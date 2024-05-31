<?php

namespace Dashed\DashedFiles\MediaLibrary\Concerns;

use Closure;

trait HasConversionsConfiguration
{
    protected bool $conversionResponsiveEnabled = true;

    protected ?Closure $conversionResponsiveCallback = null;

    protected bool $conversionMediumEnabled = true;

    protected int $conversionMediumWidth = 800;

    protected ?Closure $conversionMediumCallback = null;

    protected bool $conversionSmallEnabled = true;

    protected int $conversionSmallWidth = 400;

    protected ?Closure $conversionSmallCallback = null;

    protected bool $conversionThumbEnabled = true;

    protected int $conversionThumbWidth = 600;

    protected int $conversionThumbHeight = 600;

    protected ?Closure $conversionThumbCallback = null;

    public function conversionResponsive(bool $enabled, ?Closure $modifyUsing = null): static
    {
        $this->conversionResponsiveEnabled = $enabled;

        if ($modifyUsing !== null) {
            $this->conversionResponsiveModifyUsing($modifyUsing);
        }

        return $this;
    }

    public function conversionResponsiveModifyUsing(?Closure $callback = null): static
    {
        $this->conversionResponsiveCallback = $callback;

        return $this;
    }

    public function conversionMedium(bool $enabled, ?int $width = null, ?Closure $modifyUsing = null): static
    {
        $this->conversionMediumEnabled = $enabled;

        if ($width !== null) {
            $this->conversionMediumWidth($width);
        }

        if ($modifyUsing !== null) {
            $this->conversionMediumModifyUsing($modifyUsing);
        }

        return $this;
    }

    public function conversionMediumWidth(int $width = 800): static
    {
        $this->conversionMediumWidth = $width;

        return $this;
    }

    public function conversionMediumModifyUsing(?Closure $callback = null): static
    {
        $this->conversionMediumCallback = $callback;

        return $this;
    }

    public function conversionSmall(bool $enabled, ?int $width = null, ?Closure $modifyUsing = null): static
    {
        $this->conversionSmallEnabled = $enabled;

        if ($width !== null) {
            $this->conversionSmallWidth($width);
        }

        if ($modifyUsing !== null) {
            $this->conversionSmallModifyUsing($modifyUsing);
        }

        return $this;
    }

    public function conversionSmallWidth(int $width = 400): static
    {
        $this->conversionSmallWidth = $width;

        return $this;
    }

    public function conversionSmallModifyUsing(?Closure $callback = null): static
    {
        $this->conversionSmallCallback = $callback;

        return $this;
    }

    public function conversionThumb(bool $enabled, ?int $width = null, ?int $height = null, ?Closure $modifyUsing = null): static
    {
        $this->conversionThumbEnabled = $enabled;

        if ($width !== null) {
            $this->conversionThumbWidth($width);
        }

        if ($height !== null) {
            $this->conversionThumbHeight($height);
        }

        if ($modifyUsing !== null) {
            $this->conversionThumbModifyUsing($modifyUsing);
        }

        return $this;
    }

    public function conversionThumbWidth(int $width = 600): static
    {
        $this->conversionThumbWidth = $width;

        return $this;
    }

    public function conversionThumbHeight(int $height = 600): static
    {
        $this->conversionThumbHeight = $height;

        return $this;
    }

    public function conversionThumbModifyUsing(?Closure $callback = null): static
    {
        $this->conversionThumbCallback = $callback;

        return $this;
    }

    public function isConversionResponsiveEnabled(): bool
    {
        return $this->conversionResponsiveEnabled;
    }

    public function getConversionResponsiveCallback(): ?Closure
    {
        return $this->conversionResponsiveCallback;
    }

    public function isConversionMediumEnabled(): bool
    {
        return $this->conversionMediumEnabled;
    }

    public function getConversionMediumWidth(): int
    {
        return $this->conversionMediumWidth;
    }

    public function getConversionMediumCallback(): ?Closure
    {
        return $this->conversionMediumCallback;
    }

    public function isConversionSmallEnabled(): bool
    {
        return $this->conversionSmallEnabled;
    }

    public function getConversionSmallWidth(): int
    {
        return $this->conversionSmallWidth;
    }

    public function getConversionSmallCallback(): ?Closure
    {
        return $this->conversionSmallCallback;
    }

    public function isConversionThumbEnabled(): bool
    {
        return $this->conversionThumbEnabled;
    }

    public function getConversionThumbWidth(): int
    {
        return $this->conversionThumbWidth;
    }

    public function getConversionThumbHeight(): int
    {
        return $this->conversionThumbHeight;
    }

    public function getConversionThumbCallback(): ?Closure
    {
        return $this->conversionThumbCallback;
    }
}
