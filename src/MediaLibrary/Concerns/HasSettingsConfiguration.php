<?php

namespace Dashed\DashedFiles\MediaLibrary\Concerns;

trait HasSettingsConfiguration
{
    protected bool $showUploadBoxByDefault = false;

    protected bool $showUnstoredUploadsWarning = false;

    protected bool $showMediaInfoOnMultipleSelection = false;

    public function showUploadBoxByDefault(bool $show = true): static
    {
        $this->showUploadBoxByDefault = $show;

        return $this;
    }

    public function unstoredUploadsWarning(bool $warning = true): static
    {
        $this->showUnstoredUploadsWarning = $warning;

        return $this;
    }

    public function mediaInfoOnMultipleSelection(bool $condition = true): static
    {
        $this->showMediaInfoOnMultipleSelection = $condition;

        return $this;
    }

    public function shouldShowUploadBoxByDefault(): bool
    {
        return $this->showUploadBoxByDefault;
    }

    public function shouldShowUnstoredUploadsWarning(): bool
    {
        return $this->showUnstoredUploadsWarning;
    }

    public function shouldShowMediaInfoOnMultipleSelection(): bool
    {
        return $this->showMediaInfoOnMultipleSelection;
    }
}
