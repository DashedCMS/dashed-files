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
@if($mediaId)
    @php
        $media = mediaHelper()->getSingleMedia($mediaId, $manipulations ?: $conversion);
        $width = $width ?: ($media->width ?? null);
        $height = $height ?: ($media->height ?? null);
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
            @if($width) width="{{ $width }}" @endif
        @if($height) height="{{ $height }}" @endif
            src="{{ $url }}"
            alt="{{ $alt }}"
            loading="{{ $loading }}"
            {{ $attributes }}
        >
    @endif
@endif
