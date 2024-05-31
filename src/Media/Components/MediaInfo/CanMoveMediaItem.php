<?php

namespace Dashed\DashedFiles\Media\Components\MediaInfo;

use Filament\Facades\Filament;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Media\Components\MediaInfo;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;

/**
 * @mixin MediaInfo
 */
trait CanMoveMediaItem
{
    public bool $openMoveItemForm = false;

    public function canMove(): bool
    {
        if (! FilamentMediaLibrary::get()->getModelFolder()::exists()) {
            return false;
        }

        if (! Gate::getPolicyFor(FilamentMediaLibrary::get()->getModelItem())) {
            return true;
        }

        return Filament::auth()->user()?->can('update', $this->getMediaProperty());
    }

    protected function getMoveMediaItemForm(): ComponentContainer
    {
        return $this
            ->makeForm()
            ->schema([
                Select::make('media_library_folder_id')
                    ->disableLabel()
                    ->placeholder(__('filament-media-library::translations.components.media-info.move-media-item-form.fields.media_library_folder_id.placeholder'))
                    ->autofocus()
                    ->required()
                    ->options(function () {
                        return FilamentMediaLibrary::get()
                            ->getModelFolder()::query()
                            ->get()
                            ->mapWithKeys(function (MediaLibraryFolder $mediaLibraryFolder): array {
                                /** @var Collection $ancestorsIncludingCurrent */
                                $ancestorsIncludingCurrent = $mediaLibraryFolder->parent_id
                                    ? $mediaLibraryFolder->getAncestors()
                                    : new Collection([$mediaLibraryFolder]);

                                if ($this->lockedMediaLibraryFolder && $ancestorsIncludingCurrent->doesntContain($this->lockedMediaLibraryFolder)) {
                                    return [];
                                }

                                $pathNameIncludingCurrent = $ancestorsIncludingCurrent->implode(function (MediaLibraryFolder $mediaLibraryFolder) {
                                    return Str::limit($mediaLibraryFolder->name, 20);
                                }, ' / ');

                                return [$mediaLibraryFolder->getKey() => $pathNameIncludingCurrent];
                            })
                            ->filter()
                            ->sort()
                            ->unless($this->lockedMediaLibraryFolder, function (\Illuminate\Support\Collection $options) {
                                return $options->prepend('/', 'root');
                            });
                    }),
            ]);
    }

    public function moveMediaItem(): void
    {
        $state = $this->moveMediaItemForm->getState();

        $this->media->update([
            'folder_id' => $state['media_library_folder_id'] === 'root' ? null : $state['media_library_folder_id'],
        ]);

        unset($this->media);

        Notification::make()
            ->title(__('filament-media-library::translations.components.media-info.move-media-item-form.messages.moved.body'))
            ->success()
            ->send();

        $this->openMoveItemForm = false;

        $this->dispatch(
            'openMediaLibraryFolder',
            // Do not send a parameter with "null", otherwise the browse library component will try to retrieve the MediaLibraryFolder and throw a 404 on failure.
            ...array_filter([$this->media->folder_id]),
        )->to('media-library::media.browse-library');
    }
}
