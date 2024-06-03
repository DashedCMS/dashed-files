@props([
    'alt' => '',
    'loading' => Customsetting::get('image_force_lazy_load', null, false) ? 'lazy' : 'eager',
    'mediaId',
    'conversion' => 'medium',
])
@php
    $media = mediaHelper()->getSingleImage($mediaId, $conversion);
    $url = $media->url ?? '';
    $alt = $media->alt ?? $alt;
@endphp
<img
    test
    src="{{ $url }}"
    alt="{{ $alt }}"
    loading="{{ $loading }}"
    {{ $attributes }}
>
