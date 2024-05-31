<?php

namespace Dashed\DashedFiles\Filament\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\WithPagination;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Helpers\Livewire\CanBeRefreshed;

class MediaLibrary extends Page implements HasForms
{
    use CanBeRefreshed;
    use InteractsWithForms;
    use WithPagination;

    protected static ?string $navigationGroup = 'Media';

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'media-library::pages.media-library';

    public function displayUploadBox(): void
    {
        $this->dispatch('toggle-upload-box');
    }

    protected function getActions(): array
    {
        return [
            Action::make('upload')
                ->label(Str::ucfirst(__('filament-media-library::translations.phrases.upload')))
                ->action('displayUploadBox')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(function () {
                    if (FilamentMediaLibrary::get()->shouldShowUploadBoxByDefault()) {
                        return false;
                    }

                    if (! Gate::getPolicyFor(FilamentMediaLibrary::get()->getModelItem())) {
                        return true;
                    }

                    return Filament::auth()->user()?->can('create', FilamentMediaLibrary::get()->getModelItem());
                }),
        ];
    }

    public function getTitle(): string | Htmlable
    {
        return 'Media library V2';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Media';
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-camera';
    }

    public static function getActiveNavigationIcon(): ?string
    {
        return 'heroicon-s-camera';
    }

    public static function getNavigationLabel(): string
    {
        return 'Media library V2';
    }

    public static function getSlug(): string
    {
        return 'media-library-v2';
    }
}
