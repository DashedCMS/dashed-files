<?php

namespace Dashed\DashedFiles;

use Dashed\DashedFiles\Filament\Pages\MediaLibrary;
use Dashed\DashedFiles\Media\Components\BrowseLibrary;
use Dashed\DashedFiles\Media\Components\MediaInfo;
use Dashed\DashedFiles\Media\Components\UploadMedia;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Livewire\Livewire;
use Dashed\DashedFiles\MediaLibrary\Concerns;

class DashedFilesPlugin implements Plugin
{
    use Concerns\HasRegisterConfiguration;
    use Concerns\HasModelConfiguration;
    use Concerns\HasSettingsConfiguration;

    public function getId(): string
    {
        return 'dashed-files';
    }

    public function register(Panel $panel): void
    {
        Livewire::component('dashed-files::media.upload-media', $this->getUploadMediaComponent());
        Livewire::component('dashed-files::media.media-info', $this->getMediaInfoComponent());
        Livewire::component('dashed-files::media.browse-library', $this->getBrowseLibraryComponent());

//        Blade::directive(
//            'mediaPickerModal',
//            fn (): View => view('dashed-files::forms.components.media-picker.modal')
//        );

        $panel
            ->pages([
                MediaLibrary::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
//        FilamentView::registerRenderHook('panels::page.start', function (): string {
//            return view('dashed-files::forms.components.media-picker.modal')->render();
//        });
    }

    public static function get(): static
    {
        if (! ($currentPanel = filament()->getCurrentPanel())) {
            return app(static::class);
        }

        /** @var static */
        return $currentPanel->getPlugin(app(static::class)->getId());
    }
}
