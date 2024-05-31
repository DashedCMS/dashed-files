@php
    use Filament\Facades\Filament;
    use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
@endphp

@once
    <div class="relative z-[100] h-0" x-data="{
        statePath: null,
        folder: null,
    }">
        <x-filament::modal
            :width="FilamentMediaLibrary::get()->getMediaPickerModalWidth()"
            id="media-library-picker"
            x-on:open-modal.window="
                if ($event.detail.id !== 'media-library-picker') {
                    return;
                }

                statePath = $event.detail.getStatePath;
                $store.browseLibrary.isMultiple = $event.detail.isMultiple;

                $store.browseLibrary.selectMediaItem($event.detail.currentSelectedMediaItemIds)
                $dispatch('browse-library-load', { folder: $event.detail.folder, defaultFolder: $event.detail.defaultFolder })

                if (! $store.browseLibrary.isMultiple) {
                    $dispatch('media-item-selected', { ids: $store.browseLibrary.selectedMediaItemId, folder: $event.detail.folder })
                }

                folder = $event.detail.folder

                open()
            "
            x-init="$watch('$store.browseLibrary.latestSelectedMediaItemId', (value) => {
                $dispatch('media-item-selected', { ids: value, folder: folder });
            })"
        >
            <div class="relative h-full max-h-[80vh] overflow-y-scroll">
                <div class="flex h-full flex-row space-x-2">
                    <div class="flex-grow md:mr-8">
                        <div class="mt-2 flex items-center justify-between">
                            <h2 class="text-xl font-bold tracking-tight">
                                {{ Str::ucfirst(__('filament-media-library::translations.components.media-picker.title')) }}
                            </h2>
                            @php
                                if (! Gate::getPolicyFor(FilamentMediaLibrary::get()->getModelItem())) {
                                    $canCreate = true;
                                } else {
                                    $canCreate = Filament::auth()->user()?->can('create', FilamentMediaLibrary::get()->getModelItem());
                                }
                            @endphp

                            @if ($canCreate && FilamentMediaLibrary::get()->shouldShowUploadBoxByDefault() === false)
                                <x-filament::button
                                    tag="button"
                                    x-data="{}"
                                    x-on:click="$dispatch('toggle-upload-box')"
                                    icon="heroicon-o-arrow-up-tray"
                                >
                                    <strong>
                                        {{ Str::ucfirst(__('filament-media-library::translations.phrases.upload')) }}
                                    </strong>
                                </x-filament::button>
                            @endif
                        </div>
                        <div
                            class="-mb-[40px] mt-4 h-full max-h-full rounded-lg bg-gray-100 px-6 py-2 dark:bg-gray-950"
                        >
                            @livewire('media-library::media.browse-library', [
                                'defer' => true,
                            ])
                        </div>
                    </div>

                    <aside
                        class="sticky right-0 top-0 ml-auto hidden w-full min-w-[280px] max-w-[320px] flex-grow-0 self-start px-2 py-8 md:block"
                        @unless (FilamentMediaLibrary::get()->shouldShowMediaInfoOnMultipleSelection())
                            x-show="! $store.browseLibrary.isMultiple"
                        @endunless
                    >
                        @livewire('media-library::media.media-info')
                    </aside>
                </div>
            </div>

            <x-slot name="footer">
                <div
                    @class([
                        'flex space-x-2',
                        'justify-start' => config('filament.layout.forms.actions.alignment') === 'left',
                        'justify-center' => config('filament.layout.forms.actions.alignment') === 'center',
                        'justify-end' => config('filament.layout.forms.actions.alignment') === 'right',
                    ])
                >
                    <x-filament::button
                        outlined
                        color="gray"
                        {{-- Do not include statePath as parameter in the x-on:click to close-modal, otherwise the media picker will update the selected values --}}
                        x-on:click="$dispatch('close-modal', {id: 'media-library-picker'})"
                    >
                        {{ Str::ucfirst(__('filament-media-library::translations.phrases.cancel')) }}
                    </x-filament::button>

                    <x-filament::button
                        x-on:click="$dispatch('close-modal', {id: 'media-library-picker', statePath: statePath})"
                    >
                        {{ Str::ucfirst(__('filament-media-library::translations.phrases.update-and-close')) }}
                    </x-filament::button>
                </div>
            </x-slot>
        </x-filament::modal>
    </div>
@endonce
