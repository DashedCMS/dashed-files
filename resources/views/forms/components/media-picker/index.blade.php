@php
    use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php
        /** @var RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem $image */
        $images = $getImages();
        $state = $getState();
        $isMultiple = $isMultiple();
        $isDisabled = $isDisabled();
        $isReorderable = $isReorderable();
        $isFileNameShown = $isFileNameShown();
        $isDownloadable = $isDownloadable();

        $mediaPickerConversion = FilamentMediaLibrary::get()->getMediaPickerMediaConversion();
    @endphp

    <div
        {{ $getExtraAttributeBag() }}
        x-data="{
            state: $wire.entangle('{{ $getStatePath() }}').live,
            reorderItems(order) {
                this.state = order.map(function (item) {
                    return item.split('image-')[1]
                })
            },
        }"
        x-on:close-modal.window="
             if($event.detail.id === 'media-library-picker' && $event.detail.statePath === '{{ $getStatePath() }}' ) {
                 @if ($isMultiple)
                     state
                     =
                     $store.browseLibrary.selectedMediaItemIds;
                 @else
                     state
                     =
                     $store.browseLibrary.selectedMediaItemId;
                 @endif
             }"
        class="pb-4"
    >
        <div x-show="state != null">
            <div
                @class([
                    'grid grid-cols-4 gap-4' => $isMultiple,
                    'cursor-move' => $isMultiple && $isReorderable,
                ])
                @if ($isMultiple && ! $isDisabled && $isReorderable)
                    x-sortable
                    x-on:end="reorderItems($el.sortable.toArray())"
                @endif
            >
                @foreach ($images->filter() as $image)
                    @php
                        $media = $image->getMedia('library')->sole();
                    @endphp

                    <div
                        class="group relative overflow-hidden rounded-lg"
                        @if ($isMultiple && ! $isDisabled && $isReorderable)
                            x-sortable-handle
                            x-sortable-item="{{ 'image-' . $image->getKey() }}"
                        @endif
                    >
                        <img
                            src="{{ $media?->hasGeneratedConversion($mediaPickerConversion) ? $media?->getUrl($mediaPickerConversion) : $media?->getFullUrl() }}"
                            @if ($media?->hasResponsiveImages($mediaPickerConversion))
                                srcset="{{ $media->getSrcset($mediaPickerConversion) }}"
                                {{-- Up to 1279px (Tailwind CSS xl) use 30vw, then 20vw. --}}
                                sizes="(max-width: 1279px) 80vw, (min-width: 1279px) 50vw"
                            @endif
                            alt="{{ $image?->getMeta()->name }}"
                            class="fi-rjs-media-library-media-picker-preview relative rounded-lg"
                            wire:loading.delay.class="hidden"
                        />

                        <div class="absolute right-2 top-2 flex flex-row space-x-2">
                            @if ($isDownloadable)
                                <a
                                    href="{{ $media->getUrl() }}"
                                    title="{{ __('filament-media-library::translations.phrases.download') }}"
                                    download
                                    class="inline-flex items-center justify-center rounded-full bg-white p-1.5 shadow-sm hover:bg-gray-100"
                                >
                                    @svg('heroicon-o-arrow-down-tray', 'h-5 w-5 text-gray-500')
                                </a>
                            @endif

                            @if ($isMultiple && ! $isDisabled)
                                <div class="relative">
                                    <button
                                        type="button"
                                        class="rounded-full bg-white p-1.5 shadow-sm hover:bg-gray-100 group-hover:block"
                                        x-on:click="
                                            state = state.filter((item) => {
                                                let itemId = item

                                                if (Number.isInteger(itemId)) {
                                                    itemId = itemId.toString()
                                                }

                                                return itemId !== '{{ $image->getKey() }}'
                                            })
                                        "
                                    >
                                        @svg('heroicon-o-trash', 'h-5 w-5 text-danger-500')
                                    </button>
                                </div>
                            @endif
                        </div>

                        @if ($isFileNameShown)
                            <div
                                class="absolute bottom-0 left-0 w-full truncate bg-gray-100 px-1 py-0.5 text-xs dark:bg-gray-800 dark:text-white"
                                x-data
                                @if ($image->folder)
                                    x-tooltip.raw="{{ $image->folder->getAncestors()->implode('name', '/') }} /
                                        {{ $media->name }}"
                                @else
                                    x-tooltip.raw="{{ $media->name }}"
                                @endif
                            >
                                {{ $media->name }}
                            </div>
                        @endif

                        <div
                            class="hidden aspect-square w-full animate-pulse items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-800"
                            wire:loading.delay.class="flex"
                        >
                            <span>
                                {{ Str::ucfirst(__('filament-media-library::translations.phrases.loading')) }}...
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        @unless ($isDisabled)
            <div class="mt-4 flex flex-row items-center space-x-4">
                <x-filament::button
                    x-on:click="$dispatch('open-modal', {id: 'media-library-picker', isMultiple: {{ $isMultiple ? 'true' : 'false' }}, currentSelectedMediaItemIds: state ?? [], folder: {{ ($folder = $getFolder()) ? '\'' . $folder->getKey() . '\'' : 'null' }}, defaultFolder: {{ ($defaultFolder = $getDefaultFolder()) ? '\'' . $defaultFolder->getKey() . '\'' : 'null' }}, getStatePath: '{{ $getStatePath() }}'})"
                >
                    {{ $getButtonLabel() ?? Str::ucfirst(trans_choice('filament-media-library::translations.media.choose-image', $isMultiple ? 2 : 1)) }}
                </x-filament::button>
                <button
                    type="button"
                    x-on:click="state = {{ $isMultiple ? '[]' : 'null' }}"
                    x-show="state !== null && (! Array.isArray(state) || state.length > 0)"
                    class="text-base text-gray-400"
                    x-cloak
                >
                    {{ Str::ucfirst(__('filament-media-library::translations.media.clear-image')) }}
                </button>
                <p class="text-base text-gray-400" x-show="state == null" x-cloak>
                    {{ Str::ucfirst(__('filament-media-library::translations.media.no-image-selected-yet')) }}
                </p>
            </div>
        @endunless
    </div>
</x-dynamic-component>
