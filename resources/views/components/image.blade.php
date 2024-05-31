@php
    use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
@endphp

{{ $media->getMedia('library')->first()(FilamentMediaLibrary::get()->getComponentsImageConversion()) }}
