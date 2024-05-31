<?php

namespace Dashed\DashedFiles\Media\Components\BrowseLibrary;

use Filament\Facades\Filament;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Media\Components\BrowseLibrary;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;

/**
 * @mixin BrowseLibrary
 */
trait CanRenameMediaFolder
{
    protected function getRenameMediaFolderForm(): ComponentContainer
    {
        return $this
            ->makeForm()
            ->schema([
                TextInput::make('name')
                    ->disableLabel()
                    ->rules(['string', 'max:255'])
                    ->placeholder(Str::ucfirst(__('filament-media-library::translations.components.browse-library.modals.rename-media-folder.form.name.placeholder')))
                    ->autofocus()
                    ->required()
                    ->lazy(),
            ]);
    }

    public function openRenameMediaFolderModal(mixed $mediaLibraryFolderId): void
    {
        $mediaLibraryFolder = FilamentMediaLibrary::get()->getModelFolder()::findOrFail($mediaLibraryFolderId);

        $this->activeMediaLibraryFolder = $mediaLibraryFolder;

        $this->renameMediaFolderForm->fill([
            'name' => $mediaLibraryFolder->name,
        ]);

        $this->dispatch('open-modal', id: 'rename-media-folder');
    }

    public function closeRenameMediaFolderModal(): void
    {
        $this->activeMediaLibraryFolder = null;

        $this->dispatch('close-modal', id: 'rename-media-folder');
    }

    public function renameMediaFolder(): void
    {
        $state = $this->renameMediaFolderForm->getState();

        $mediaLibraryFolder = $this->activeMediaLibraryFolder;

        $this->activeMediaLibraryFolder = null;

        $mediaLibraryFolder->update($state);

        Notification::make()
            ->title(__('filament-media-library::translations.components.browse-library.modals.rename-media-folder.messages.renamed.body'))
            ->success()
            ->send();

        $this->dispatch('$refresh')->to('media-library::media.media-info');

        $this->closeRenameMediaFolderModal();
    }

    public function canRenameFolder(MediaLibraryFolder $mediaLibraryFolder): bool
    {
        if (! Gate::getPolicyFor(FilamentMediaLibrary::get()->getModelFolder())) {
            return true;
        }

        if (Auth::guest()) {
            return true;
        }

        return Filament::auth()->user()->can('update', $mediaLibraryFolder);
    }
}
