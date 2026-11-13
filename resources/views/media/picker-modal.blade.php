{{--
    Het keuzevenster van de mediabibliotheek. Dicht is dit alleen de schil
    van het venster: de lijst komt er pas in nadat de browser openPicker()
    heeft aangeroepen. Stijl en script staan hier bewust niet: die zet de
    render hook (media.picker-host) één keer in de pagina, buiten dit
    component, zodat een render ze niet telkens opnieuw meestuurt.
--}}
<div
    class="dashed-media-picker-host"
    data-dashed-media-picker-host="{{ \Dashed\DashedFiles\Livewire\MediaPickerModal::MODAL_ID }}"
    data-upload-max-kb="{{ \Dashed\DashedFiles\Livewire\MediaPickerModal::mediaUploadMaxKb() }}"
    data-browser-posters="{{ \Dashed\DashedFiles\Classes\Preview\MediaPreviews::browserMakesPosters() ? '1' : '0' }}"
    x-data="{}"
    x-init="window.dashedMedia && window.dashedMedia.registerHost && window.dashedMedia.registerHost($el, $wire)"
    x-on:dashed-media-picked="window.dashedMedia.hostPicked($event)"
    x-on:dashed-media-pick-cancel="window.dashedMedia.hostClose()"
    x-on:modal-closed.window="window.dashedMedia.hostClosed($event)"
>
    <x-filament::modal
        :id="\Dashed\DashedFiles\Livewire\MediaPickerModal::MODAL_ID"
        width="7xl"
        :heading="__('Kies uit de mediabibliotheek')"
        :autofocus="false"
        class="dashed-media-picker-modal"
    >
        @if ($open)
            <div wire:key="dashed-media-pick-{{ $opened }}" class="dashed-media-pick">
                @include('dashed-files::media.browser', ['mediaConfig' => $this->pickerBrowserConfig(), 'mediaAssets' => false])
            </div>
        @else
            <div wire:key="dashed-media-pick-wait" class="dashed-media-picker-wait" aria-hidden="true">
                <span class="dashed-media-spin"></span> {{ __('Bezig met laden...') }}
            </div>
        @endif
    </x-filament::modal>
</div>
