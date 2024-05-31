@php
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Str;
    use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
    use RalphJSmit\Filament\MediaLibrary\Media\DataTransferObjects\MediaItemMeta;

    /** @var $mediaItemMeta MediaItemMeta */
@endphp

<div class="h-full">
    <div wire:loading.flex wire:target="setMedia" class="my-8 hidden min-h-full w-full items-center justify-center">
        <x-media-library::spinner/>
    </div>
    
    <div
        class="space-y-6 md:pb-16"
        x-data
        x-on:media-item-selected.window="$wire.setMedia($event.detail.ids, $event.detail.folder)"
        wire:loading.remove
        wire:target="setMedia"
    >
        <div>
            <div class="relative" wire:key="image">
                @if ($mediaItemMeta)
                    {{ \Filament\Support\Facades\FilamentView::renderHook(\RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary::RENDER_HOOK_MEDIA_INFO_PREVIEW_BEFORE) }}
                    
                    <div class="aspect-w-10 aspect-h-7 block w-full overflow-hidden rounded-lg">
                        <img src="{{ $mediaItemMeta->url }}" alt="" class="object-cover"/>
                    </div>
                    
                    {{ \Filament\Support\Facades\FilamentView::renderHook(\RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary::RENDER_HOOK_MEDIA_INFO_PREVIEW_AFTER) }}
                    
                    <form wire:submit.prevent="submitReplaceMediaForm" x-data>
                        <input
                            wire:model="replaceMediaUpload"
                            type="file"
                            accept="{{ FilamentMediaLibrary::get()->getAcceptedFileTypes()->implode(',') }}"
                            x-ref="replaceMediaUpload"
                            class="hidden"
                        />
                        
                        <button
                            x-on:click="$refs.replaceMediaUpload.click()"
                            class="group absolute -right-2 top-4 h-7 w-7 rounded-full border border-gray-300 bg-white p-1 hover:border-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800"
                        >
                            <div
                                class="full absolute left-0 top-1/2 hidden -translate-y-1/2 translate-x-[calc(-100%-6px)] whitespace-nowrap rounded bg-gray-900 px-2 py-1 text-white group-hover:block"
                            >
                                {{ Str::ucfirst(__('filament-media-library::translations.phrases.replace-media')) }}
                            </div>
                            
                            @svg('heroicon-o-arrows-right-left', 'text-current', ['wire:loading.remove', 'wire:target' => 'submitReplaceMediaForm, replaceMediaUpload'])
                            
                            <span wire:loading wire:target="submitReplaceMediaForm, replaceMediaUpload">
                                <x-media-library::spinner class="text-current"/>
                            </span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
        
        {{ \Filament\Support\Facades\FilamentView::renderHook(\RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary::RENDER_HOOK_MEDIA_INFO_TITLE_BEFORE) }}
        
        <div class="mt-4 flex items-start justify-between" wire:key="name">
            <div>
                <h2 class="break-words text-lg font-medium text-gray-900 dark:text-gray-100">
                    <span style="word-break: break-word">
                        {{ $mediaItemMeta->name ?? Str::of(__('filament-media-library::translations.phrases.select-image'))->ucfirst() }}
                    </span>
                </h2>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ $mediaItemMeta->human_readable_size ?? Str::ucfirst(__('filament-media-library::translations.sentences.select-image-to-view-info')) }}
                </p>
            </div>
        </div>
        
        {{ \Filament\Support\Facades\FilamentView::renderHook(\RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary::RENDER_HOOK_MEDIA_INFO_TITLE_AFTER) }}
        
        @if ($this->media && $mediaItemMeta)
            <div wire:key="mediaItemMeta">
                <h3 class="font-medium text-gray-900 dark:text-gray-100">
                    {{ Str::ucfirst(__('filament-media-library::translations.information')) }}
                </h3>
                
                <dl
                    class="mt-2 divide-y divide-gray-200 border-b border-t border-gray-200 dark:divide-gray-600 dark:border-gray-600"
                >
                    @foreach ($this->getInformation($this->media, $mediaItemMeta) as $description => $value)
                        <div class="flex items-center justify-between gap-x-2 py-3 text-sm font-medium">
                            <dt class="text-gray-500 dark:text-gray-400">
                                {{ Str::ucfirst($description) }}
                            </dt>
                            <dd class="text-right text-gray-900 dark:text-gray-100">
                                {{ $value }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
            @if ($this->canEdit())
                <div x-data="{ openEditForm: $wire.entangle('openEditForm') }" wire:key="editForm">
                    <div class="flex flex-row items-center justify-between gap-x-4">
                        <div>
                            <h3 class="font-medium text-gray-900 dark:text-gray-100">
                                {{ Str::ucfirst(__('filament-media-library::translations.edit-media')) }}
                            </h3>
                            <p class="text-sm text-gray-400">
                                {{ Str::ucfirst(__('filament-media-library::translations.edit-media-description')) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            x-on:click="openEditForm = true"
                            x-show="openEditForm === false"
                            class="flex h-8 w-8 flex-shrink-0 flex-grow-0 items-center justify-center rounded-full bg-white text-gray-400 hover:bg-gray-100 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:hover:text-gray-300"
                        >
                            <x-media-library::icons.pencil/>
                        </button>
                    </div>
                    
                    <div x-show="openEditForm" class="mt-4">
                        <form wire:submit.prevent="submit">
                            {{ $this->form }}
                            
                            <x-filament::button tag="button" type="submit" wire:click="submit" class="mt-4">
                                {{ Str::of(__('filament-media-library::translations.phrases.save'))->ucfirst() }}
                            </x-filament::button>
                        </form>
                    </div>
                </div>
            @endif
            
            @if ($this->canMove())
                <div x-data="{ openMoveItemForm: $wire.entangle('openMoveItemForm') }" wire:key="moveItemForm">
                    <div class="flex flex-row items-center justify-between gap-x-4">
                        <div>
                            <h3 class="font-medium text-gray-900 dark:text-gray-100">
                                {{ Str::ucfirst(__('filament-media-library::translations.move-media')) }}
                            </h3>
                            <p class="text-sm text-gray-400">
                                {{ Str::ucfirst(__('filament-media-library::translations.move-media-description', ['name' => $mediaItemMeta->folder_name])) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            x-on:click="openMoveItemForm = true"
                            x-show="openMoveItemForm === false"
                            class="flex h-8 w-8 flex-shrink-0 flex-grow-0 items-center justify-center rounded-full bg-white text-gray-400 hover:bg-gray-100 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:bg-gray-700 dark:hover:text-gray-300"
                        >
                            <x-media-library::icons.pencil/>
                        </button>
                    </div>
                    
                    <div x-show="openMoveItemForm" class="mt-4">
                        <form wire:submit.prevent="moveMediaItem">
                            {{ $this->moveMediaItemForm }}
                            
                            <x-filament::button tag="button" type="submit" class="mt-4">
                                {{ Str::of(__('filament-media-library::translations.phrases.move-media'))->ucfirst() }}
                            </x-filament::button>
                        </form>
                    </div>
                </div>
            @endif
            
            {{ \Filament\Support\Facades\FilamentView::renderHook(\RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary::RENDER_HOOK_MEDIA_INFO_ACTIONS_BEFORE) }}
            
            <div
                x-data="{ deletePanelOpen: false }"
                x-on:close-delete-panel.window="deletePanelOpen = false"
                wire:key="deletePanel"
            >
                <h3 class="font-medium text-gray-900 dark:text-gray-100">
                    {{ Str::ucfirst(__('filament-media-library::translations.actions')) }}
                </h3>
                <div class="-mx-2 -my-2 mt-4 flex flex-wrap" x-show="!deletePanelOpen">
                    <div
                        @class([
                            'w-1/2' => $canDelete = $this->canDelete(),
                            'w-full' => ! $canDelete,
                            'p-2',
                        ])
                    >
                        <x-filament::button tag="a" :href="$mediaItemMeta->full_url" target="_blank" class="w-full">
                            {{ Str::ucfirst(__('filament-media-library::translations.phrases.view')) }}
                        </x-filament::button>
                    </div>
                    
                    @if ($this->canDelete())
                        <div class="w-1/2 p-2">
                            <x-filament::button
                                tag="button"
                                @click="deletePanelOpen = true"
                                class="w-full"
                                color="gray"
                            >
                                {{ Str::ucfirst(__('filament-media-library::translations.phrases.delete')) }}
                            </x-filament::button>
                        </div>
                    @endif
                    
                    <div class="w-full p-2">
                        @unless ($regenerationRequested)
                            <x-filament::button
                                tag="button"
                                wire:click="regenerateMediaItem"
                                class="w-full"
                                color="gray"
                            >
                                {{ Str::ucfirst(__('filament-media-library::translations.phrases.regenerate')) }}
                            </x-filament::button>
                        @else
                            <x-filament::button
                                tag="a"
                                class="w-full"
                                color="gray"
                                icon="heroicon-o-information-circle"
                                :tooltip="Str::ucfirst(__('filament-media-library::translations.media.will-be-available-soon'))"
                            >
                                {{ Str::ucfirst(__('filament-media-library::translations.phrases.requested')) }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
                
                @if ($this->canDelete())
                    <div class="" x-show="deletePanelOpen" x-cloak>
                        <p class="mt-4 text-gray-400">
                            {{ Str::ucfirst(__('filament-media-library::translations.warnings.delete-media', ['filename' => $mediaItemMeta->name])) }}
                        </p>
                        <x-filament::button tag="button" wire:click="deleteImage" class="mt-4 w-full" color="danger">
                            {{ Str::ucfirst(__('filament-media-library::translations.phrases.delete')) }}
                        </x-filament::button>
                    </div>
                @endif
            </div>
            
            {{ \Filament\Support\Facades\FilamentView::renderHook(\RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary::RENDER_HOOK_MEDIA_INFO_ACTIONS_AFTER) }}
        @endif
    </div>
</div>
