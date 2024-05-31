<?php

namespace Dashed\DashedFiles\MediaLibrary;

use Illuminate\Support\Collection;
use RalphJSmit\Filament\MediaLibrary\Media\Components\BrowseLibrary;
use RalphJSmit\Filament\MediaLibrary\Media\Components\MediaInfo;
use RalphJSmit\Filament\MediaLibrary\Media\Components\UploadMedia;

trait HasComponentRegistration
{
    protected string $livewireUploadMediaComponent = UploadMedia::class;

    protected string $livewireMediaInfoComponent = MediaInfo::class;

    protected string $livewireBrowseLibraryComponent = BrowseLibrary::class;

    public function uploadMediaComponent(string $component): static
    {
        $this->livewireUploadMediaComponent = $component;

        return $this;
    }

    public function mediaInfoComponent(string $component): static
    {
        $this->livewireMediaInfoComponent = $component;

        return $this;
    }

    public function browseLibraryComponent(string $component): static
    {
        $this->livewireBrowseLibraryComponent = $component;

        return $this;
    }

    public function getUploadMediaComponent(): string
    {
        return $this->livewireUploadMediaComponent;
    }

    public function getMediaInfoComponent(): string
    {
        return $this->livewireMediaInfoComponent;
    }

    public function getBrowseLibraryComponent(): string
    {
        return $this->livewireBrowseLibraryComponent;
    }
}
