@php
    use Illuminate\Support\Str;
    use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
@endphp

<div>
    <form
        wire:submit.prevent="uploadFiles"
        class="rounded-lg border border-dashed border-gray-300 px-6 py-6 dark:border-gray-800"
    >
        <div class="">
            {{ $this->uploadForm }}
        </div>

        @if ($uploads)
            @if (FilamentMediaLibrary::get()->shouldShowUnstoredUploadsWarning())
                <div class="flex flex-row items-center gap-x-4 pt-4">
                    @svg('heroicon-o-exclamation-circle', 'h-6 w-6')
                    <p>
                        {{ trans_choice('filament-media-library::translations.media.warning-unstored-uploads', $uploads) }}
                    </p>
                </div>
            @endif

            <x-filament::button type="submit" class="mt-4" wire:loading.remove>
                {{ Str::of(trans_choice('filament-media-library::translations.phrases.store-images', $uploads))->ucfirst() }}
            </x-filament::button>
        @endif

        <div class="mt-5 items-center space-x-4" wire:loading.flex>
            <x-media-library::spinner class="!ml-1" />
            <p class="text-sm text-gray-700">
                {{ Str::ucfirst(__('filament-media-library::translations.media.storing-files')) }}
            </p>
        </div>
    </form>
</div>
