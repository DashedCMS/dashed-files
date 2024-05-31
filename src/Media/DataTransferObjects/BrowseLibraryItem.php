<?php

namespace Dashed\DashedFiles\Media\DataTransferObjects;

use Dashed\DashedFiles\Models\MediaFile;
use Dashed\DashedFiles\Models\MediaFolder;
use Illuminate\Support\Carbon;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;

class BrowseLibraryItem
{
    public function __construct(
        public MediaFile | MediaFolder $item,
    ) {
    }

    public function isMediaLibraryItem(): bool
    {
        return $this->item instanceof MediaFile;
    }

    public function isMediaLibraryFolder(): bool
    {
        return $this->item instanceof MediaFolder;
    }

    public function getChildrenCount(): int
    {
        if ($this->isMediaLibraryItem()) {
            return 0;
        }

        return $this->item->children_count + $this->item->media_library_items_count;
    }

    public function getCreatedAt(): Carbon
    {
        return $this->item->created_at;
    }

    public function getName(): string
    {
        if ($this->isMediaLibraryFolder()) {
            return $this->item->name;
        }

        return $this->item->getItem()->name;
    }
}
