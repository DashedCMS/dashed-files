{{--
    Het keuzevenster van de mediabibliotheek. Het component rendert alleen
    deze schil, en rendert niet opnieuw bij openen of sluiten: de browser
    zet de lijst (media.browser, één keer opgehaald via openPicker()) zelf in
    de houder hieronder, onder wire:ignore. Stijl en script staan hier bewust
    niet: die zet de render hook (media.picker-host) één keer in de pagina.
--}}
<div
    class="dashed-media-picker-host"
    data-dashed-media-picker-host="{{ \Dashed\DashedFiles\Livewire\MediaPickerModal::MODAL_ID }}"
    data-upload-max-kb="{{ \Dashed\DashedFiles\Livewire\MediaPickerModal::mediaUploadMaxKb() }}"
    data-browser-posters="{{ \Dashed\DashedFiles\Classes\Preview\MediaPreviews::browserMakesPosters() ? '1' : '0' }}"
    x-data="{}"
    x-init="window.dashedMedia && window.dashedMedia.registerHost && window.dashedMedia.registerHost($el, $wire)"
    x-on:dashed-media-ready.window="window.dashedMedia.registerHost($el, $wire)"
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
        <div wire:ignore class="dashed-media-pick" data-dashed-media-pick-mount>
            <div class="dashed-media-picker-wait" aria-hidden="true">
                <span class="dashed-media-spin"></span> {{ __('Bezig met laden...') }}
            </div>
        </div>
    </x-filament::modal>
</div>
