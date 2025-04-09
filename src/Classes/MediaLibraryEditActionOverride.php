<?php

namespace App\Classes;

use RalphJSmit\Filament\MediaLibrary\FilamentTipTap\Actions\MediaLibraryEditAction;
use RalphJSmit\Filament\MediaLibrary\Media\DataTransferObjects\MediaItemMeta;

class MediaLibraryEditActionOverride extends MediaLibraryEditAction
{
    protected function getMediaLibraryItemMeta(MediaLibraryItem|\RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem $mediaLibraryItem): MediaItemMeta
    {
        $mediaItemMeta = parent::getMediaLibraryItemMeta($mediaLibraryItem);

        $mediaItemMeta->url = $mediaItemMeta->full_url;

        return $mediaItemMeta;
    }
}
