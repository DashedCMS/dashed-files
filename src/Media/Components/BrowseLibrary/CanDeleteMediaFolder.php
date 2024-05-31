<?php

namespace Dashed\DashedFiles\Media\Components\BrowseLibrary;

use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Media\Components\BrowseLibrary;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;

/**
 * @mixin BrowseLibrary
 */
trait CanDeleteMediaFolder
{
    public function mountCanDeleteMediaFolder(): void
    {
        $this->deleteMediaFolderForm->fill();
    }

    public function openDeleteMediaFolderModal(mixed $mediaLibraryFolderId): void
    {
        $mediaLibraryFolder = FilamentMediaLibrary::get()->getModelFolder()::findOrFail($mediaLibraryFolderId);

        $this->activeMediaLibraryFolder = $mediaLibraryFolder;

        $this->dispatch('open-modal', id: 'delete-media-folder');
    }

    public function closeDeleteMediaFolderModal(): void
    {
        $this->activeMediaLibraryFolder = null;

        $this->dispatch('close-modal', id: 'delete-media-folder');
    }

    public function deleteMediaFolder(): void
    {
        $state = $this->deleteMediaFolderForm->getState();

        $mediaLibraryFolder = $this->activeMediaLibraryFolder;

        $this->activeMediaLibraryFolder = null;

        if ($state['include_children']) {
            $mediaLibraryFolder->deleteRecursive();
        } else {
            $mediaLibraryFolder->delete();
        }

        $this->deleteMediaFolderForm->fill();

        Notification::make()
            ->title(__('filament-media-library::translations.components.browse-library.modals.delete-media-folder.messages.deleted.body'))
            ->success()
            ->send();

        $this->dispatch('$refresh')->to('media-library::media.media-info');

        $this->closeDeleteMediaFolderModal();
    }

    public function canDeleteFolder(MediaLibraryFolder $mediaLibraryFolder): bool
    {
        if (! Gate::getPolicyFor(FilamentMediaLibrary::get()->getModelFolder())) {
            return true;
        }

        if (Auth::guest()) {
            return true;
        }

        return Filament::auth()->user()->can('delete', $mediaLibraryFolder);
    }

    protected function getDeleteMediaFolderForm(): Form
    {
        return $this
            ->makeForm()
            ->schema([
                Forms\Components\Checkbox::make('include_children')
                    ->label(__('filament-media-library::translations.components.browse-library.modals.delete-media-folder.form.fields.include_children.label'))
                    ->helperText(__('filament-media-library::translations.components.browse-library.modals.delete-media-folder.form.fields.include_children.helper_text'))
                    ->default(false),
            ]);
    }
}
