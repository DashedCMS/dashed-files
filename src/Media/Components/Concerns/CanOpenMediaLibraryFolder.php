<?php

namespace Dashed\DashedFiles\Media\Components\Concerns;

use Livewire\Attributes\Computed;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;

/**
 * @property-read MediaLibraryFolder $mediaLibraryFolder
 */
trait CanOpenMediaLibraryFolder
{
    public null | int | string $mediaLibraryFolderId = null;

    public function bootCanOpenMediaLibraryFolder(): void
    {
        $this->listeners['openMediaLibraryFolder'] = 'openMediaLibraryFolder';
    }

    public function openMediaLibraryFolder(null | int | string $mediaLibraryFolderId): void
    {
        $this->mediaLibraryFolderId = $mediaLibraryFolderId;

        unset($this->mediaLibraryFolder);
    }

    #[Computed]
    public function mediaLibraryFolder(): ?MediaLibraryFolder
    {
        if (! $this->mediaLibraryFolderId) {
            return null;
        }

        return FilamentMediaLibrary::get()->getModelFolder()::find($this->mediaLibraryFolderId);
    }
}
