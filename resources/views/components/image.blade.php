@props([
    'alt' => '',
    'loading' => Customsetting::get('image_force_lazy_load', null, false) ? 'lazy' : 'eager',
    'mediaId',
    'conversion' => 'medium',
    'manipulations' => [],
    'autoplay' => true,
    'controls' => false,
    'muted' => true,
    'loop' => true,
    'height' => '',
    'width' => '',
])
@php
    $media = mediaHelper()->getSingleMedia($mediaId, $manipulations ?: $conversion);
    $url = $media->url ?? '';
    $alt = $media->altText ?? $alt;
    $isVideo = $media->isVideo ?? false;
@endphp
@if($isVideo)
    <video {{ $attributes }}
           @if($controls) controls @endif
           @if($autoplay) autoplay @endif
           @if($muted) muted @endif
           @if($loop) loop @endif
           loading="{{ $loading }}">
        <source src="{{ $url }}" type="video/mp4">
        Your browser does not support the video tag.
    </video>
@else
    <img
        src="{{ $url }}"
        alt="{{ $alt }}"
        loading="{{ $loading }}"
        {{ $attributes }}
    >
@endif
