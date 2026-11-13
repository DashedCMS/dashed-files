{{--
    Via de render hook BODY_END, op elke paneelpagina van de eigen motor:
    de stijl en het script van het mediaveld en het keuzevenster, en het
    venster zelf. Stijl en script staan hier, buiten elk Livewire-component:
    zo staan ze precies één keer per paginalading in de pagina en raakt geen
    Livewire-render ze ooit aan. De beheerpagina brengt de stijl en het script
    van de lijst al zelf mee; die komen er dan niet nog eens bij.
--}}
@include('dashed-files::media.field-styles')
@unless (\Dashed\DashedFiles\Classes\MediaAssets::rendered())
    @include('dashed-files::media.styles')
    @include('dashed-files::media.scripts')
@endunless
@include('dashed-files::media.picker-scripts')

@livewire(\Dashed\DashedFiles\Livewire\MediaPickerModal::COMPONENT, [], key('dashed-media-picker-modal'))
