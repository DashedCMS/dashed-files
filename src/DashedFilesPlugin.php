<?php

namespace Dashed\DashedFiles;

use Dashed\DashedFiles\Filament\Pages\MediaLibrary;
use Dashed\DashedFiles\MediaLibrary\HasComponentRegistration;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

class DashedFilesPlugin implements Plugin
{
    use HasComponentRegistration;

    public function getId(): string
    {
        return 'dashed-files';
    }

    public function register(Panel $panel): void
    {
        Livewire::component('dashed-files::media.upload-media', $this->getUploadMediaComponent());
        Livewire::component('dashed-files::media.media-info', $this->getMediaInfoComponent());
        Livewire::component('dashed-files::media.browse-library', $this->getBrowseLibraryComponent());

        Blade::directive(
            'mediaPickerModal',
            fn (): View => view('dashed-files::forms.components.media-picker.modal')
        );

        $panel
            ->pages([
                MediaLibrary::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        FilamentView::registerRenderHook('panels::page.start', function (): string {
            return view('dashed-files::forms.components.media-picker.modal')->render();
        });
    }
}
