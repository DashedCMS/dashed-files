<?php

namespace Dashed\DashedFiles\MediaLibrary\Concerns;

use Dashed\DashedFiles\Models\MediaFile;
use Dashed\DashedFiles\Models\MediaFolder;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;

trait HasModelConfiguration
{
    protected string $modelItem = MediaFile::class;

    protected string $modelFolder = MediaFolder::class;

    /**
     * Use the below setting to customize the model used for media library items.
     * This allows you to override the model for an item and customize it.
     * Make sure to always extend the original model, so that you will not accidentally
     * lose functionality or forget to upgrade functions.
     */
    public function modelItem(string $className): static
    {
        $this->modelItem = $className;

        return $this;
    }

    public function modelFolder(string $className): static
    {
        $this->modelFolder = $className;

        return $this;
    }

    /**
     * @return class-string<MediaFile>
     */
    public function getModelItem(): string
    {
        return $this->modelItem;
    }

    /**
     * @return class-string<MediaFolder>
     */
    public function getModelFolder(): string
    {
        return $this->modelFolder;
    }
}
