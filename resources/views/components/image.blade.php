@props([
    'alt' => '',
    'loading' => null,
    'mediaId',
    'conversion' => 'medium',
    'manipulations' => [],
    'autoplay' => true,
    'controls' => false,
    'muted' => true,
    'loop' => true,
    'height' => '',
    'width' => '',
    'fetchpriority' => null,
])
@if($mediaId)
    @php
        $media = mediaHelper()->getSingleMedia($mediaId, $manipulations ?: $conversion);
        $width = $width ?: ($media->width ?? null);
        $height = $height ?: ($media->height ?? null);
        $url = $media->url ?? '';
        $alt = $media->altText ?? $alt;
        $isVideo = $media->isVideo ?? ($media->is_video ?? false);

        // Compute output dimensions from original aspect ratio + manipulations
        $origW = $media->width ?? null;
        $origH = $media->height ?? null;
        $aspectRatio = ($origW && $origH) ? $origW / $origH : null;

        if (! empty($manipulations['fit'])) {
            $width = $width ?: ($manipulations['fit'][0] ?? null);
            $height = $height ?: ($manipulations['fit'][1] ?? null);
        } elseif (! empty($manipulations['widen'])) {
            $width = $width ?: $manipulations['widen'];
            if (! $height && $aspectRatio) {
                $height = (int) round($manipulations['widen'] / $aspectRatio);
            }
        } elseif (! empty($manipulations['heighten'])) {
            $height = $height ?: $manipulations['heighten'];
            if (! $width && $aspectRatio) {
                $width = (int) round($manipulations['heighten'] * $aspectRatio);
            }
        }

        // Fall back to original dimensions if still missing
        if (! $width) { $width = $origW; }
        if (! $height) { $height = $origH; }

        if ($loading === null) {
            if (config('dashed-core.performance.lazy_images_default', true)) {
                $loading = app(\Dashed\DashedCore\Performance\Images\ImagePriorityTracker::class)->next();
            } else {
                $loading = \Dashed\DashedCore\Models\Customsetting::get('image_force_lazy_load', null, false) ? 'lazy' : 'eager';
            }
        }

        if ($fetchpriority === null) {
            $fetchpriority = $loading === 'eager' ? 'high' : 'auto';
        }
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
            width="{{ $width ?: '' }}"
            height="{{ $height ?: '' }}"
            src="{{ $url }}"
            alt="{{ $alt }}"
            loading="{{ $loading }}"
            decoding="async"
            fetchpriority="{{ $fetchpriority }}"
            {{ $attributes }}
        >
    @endif
@endif
