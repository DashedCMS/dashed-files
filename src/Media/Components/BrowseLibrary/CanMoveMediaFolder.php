<?php

namespace Dashed\DashedFiles\Media\Components\BrowseLibrary;

use Filament\Facades\Filament;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Media\Components\BrowseLibrary;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;

/**
 * @mixin BrowseLibrary
 */
trait CanMoveMediaFolder
{
    protected function getMoveMediaFolderForm(): ComponentContainer
    {
        return $this
            ->makeForm()
            ->schema([
                Select::make('media_library_folder_id')
                    ->disableLabel()
                    ->placeholder(__('filament-media-library::translations.components.browse-library.modals.move-media-folder.form.media_library_folder_id.placeholder'))
                    ->autofocus()
                    ->required()
                    ->options(function () {
                        if (! $this->activeMediaLibraryFolder) {
                            return collect();
                        }

                        return FilamentMediaLibrary::get()
                            ->getModelFolder()::query()
                            // First, we reject the immediate children of the current active media folder.
                            // These are easy, because they have a parent ID present. This reduces the
                            // nr of "->getAncestors()" queries in the next `mapWithKeys()` below.
                            ->get()
                            ->mapWithKeys(function (MediaLibraryFolder $mediaLibraryFolder): array {
                                // Reject the current active media folder.
                                if ($mediaLibraryFolder->is($this->activeMediaLibraryFolder)) {
                                    return [];
                                }

                                $ancestorsIncludingCurrent = $mediaLibraryFolder->parent_id
                                    ? $mediaLibraryFolder->getAncestors()
                                    : new Collection([$mediaLibraryFolder]);

                                if ($this->lockedMediaLibraryFolder && $ancestorsIncludingCurrent->doesntContain($this->lockedMediaLibraryFolder)) {
                                    return [];
                                }

                                // If one of the ancestors of this folder is the current folder, then reject. We cannot
                                // move a parent folder into a nested child folder of itself, which would cause loops.
                                if ($ancestorsIncludingCurrent->where($this->activeMediaLibraryFolder->getKeyName(), $this->activeMediaLibraryFolder->getKey())->isNotEmpty()) {
                                    return [];
                                }

                                $pathNameIncludingCurrent = $ancestorsIncludingCurrent
                                    ->implode(fn (MediaLibraryFolder $mediaLibraryFolder) => Str::limit($mediaLibraryFolder->name, 20), ' / ');

                                return [$mediaLibraryFolder->getKey() => $pathNameIncludingCurrent];
                            })
                            ->filter()
                            ->sort()
                            ->when($this->activeMediaLibraryFolder->parent_id && ! $this->lockedMediaLibraryFolder, function (\Illuminate\Support\Collection $options) {
                                return $options->prepend('/', 'root');
                            });
                    }),
            ]);
    }

    public function openMoveMediaFolderModal(mixed $mediaLibraryFolderId): void
    {
        $mediaLibraryFolder = FilamentMediaLibrary::get()->getModelFolder()::findOrFail($mediaLibraryFolderId);

        $this->activeMediaLibraryFolder = $mediaLibraryFolder;

        $this->moveMediaFolderForm->fill();

        $this->dispatch('open-modal', id: 'move-media-folder');
    }

    public function closeMoveMediaFolderModal(): void
    {
        $this->activeMediaLibraryFolder = null;

        $this->dispatch('close-modal', id: 'move-media-folder');
    }

    public function moveMediaFolder(): void
    {
        $state = $this->moveMediaFolderForm->getState();

        if ($state['media_library_folder_id'] === 'root') {
            $this->activeMediaLibraryFolder->update([
                'parent_id' => null,
            ]);
        } else {
            $mediaLibraryFolder = FilamentMediaLibrary::get()->getModelFolder()::find($state['media_library_folder_id']);

            $this->activeMediaLibraryFolder->update([
                'parent_id' => $mediaLibraryFolder->getKey(),
            ]);
        }

        Notification::make()
            ->title(__('filament-media-library::translations.components.browse-library.modals.move-media-folder.messages.moved.body'))
            ->success()
            ->send();

        $this->dispatch('$refresh')->to('media-library::media.media-info');

        $this->closeMoveMediaFolderModal();
    }

    public function canMoveFolder(MediaLibraryFolder $mediaLibraryFolder): bool
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
