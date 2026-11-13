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
    'preset' => null,
    'widths' => null,
])
@if($mediaId)
    @php
        // Alleen met de props `preset` of `widths`: een srcset uit de breedtes die al gegenereerd zijn.
        $responsive = null;
        // `sizes` en `srcset` zijn bewust geen props: een thema dat ze zelf
        // meegeeft krijgt ze ongewijzigd op de img terug.
        // Een eigen srcset van het thema wint altijd: dan komt er geen ladder van de preset bij.
        $ownSrcset = filled($attributes->get('srcset'));
        if (($preset || $widths) && ! $ownSrcset) {
            $presetObject = is_string($preset) ? \Dashed\DashedFiles\Classes\MediaPresets::get($preset) : null;
            if ($presetObject) {
                $manipulations = $presetObject->toConversion();
            }
            $responsive = mediaHelper()->responsive($mediaId, $presetObject ?? ($manipulations ?: $conversion), is_array($widths) ? $widths : null);

            // Met een srcset horen breedte en hoogte de verhouding van het
            // formaat te dragen, niet die van het origineel.
            if ($responsive && ! $width && ! $height && is_array($manipulations)) {
                $ratio = ($responsive->media->width ?? null) && ($responsive->media->height ?? null) ? $responsive->media->width / $responsive->media->height : null;

                if (! empty($manipulations['fit'])) {
                    [$width, $height] = [(int) ($manipulations['fit'][0] ?? 0) ?: '', (int) ($manipulations['fit'][1] ?? 0) ?: ''];
                } elseif (! empty($manipulations['widen']) && $ratio) {
                    [$width, $height] = [(int) $manipulations['widen'], (int) round($manipulations['widen'] / $ratio)];
                }
            }
        }
        $media = mediaHelper()->getSingleMedia($mediaId, $manipulations ?: $conversion);
        // Wat de aanroeper zelf meegaf, voordat hieronder iets wordt aangevuld.
        $givenWidth = $width;
        $givenHeight = $height;
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

        // Alles hieronder doet alleen iets voor een item met een gezet
        // focuspunt, een srcset of een AVIF-variant; anders blijft de
        // uitvoer byte voor byte wat hij was.
        $pictureOpen = '';
        $pictureClose = '';

        if (is_object($media) && ! $isVideo) {
            $focal = $media->focal ?? null;

            if (is_array($focal) && isset($focal['x'], $focal['y']) && ! ((int) $focal['x'] === 50 && (int) $focal['y'] === 50)) {
                $attributes = $attributes->style(['object-position: ' . (int) $focal['x'] . '% ' . (int) $focal['y'] . '%']);
            }

            // `sizes` van het thema (het attribuut) gaat voor die van de preset.
            $sizesText = filled($attributes->get('sizes')) ? (string) $attributes->get('sizes') : ($responsive->sizes ?? null);
            // Een srcset met w-descriptors zonder `sizes` laat de browser van
            // 100vw uitgaan en dus het grootste bestand halen: dan liever geen srcset.
            $ladder = $responsive && filled($responsive->srcset) && filled($sizesText);

            if ($ladder) {
                $attributes = $attributes->merge(['srcset' => $responsive->srcset, 'sizes' => $sizesText]);
            }

            $avifSet = null;

            if (\Dashed\DashedFiles\Classes\MediaLibrary::avifEnabled() && ! $ownSrcset) {
                // Met een ladder alleen de AVIF-ladder: één los adres zou de keuze van de browser afpakken.
                $avifSet = $ladder ? $responsive->avifSrcset : ($media->avif ?? null);
            }

            if (filled($avifSet)) {
                $pictureOpen = '<picture style="display:contents"><source type="image/avif" srcset="' . e($avifSet) . '"'
                    . ($ladder ? ' sizes="' . e($sizesText) . '"' : '') . '>';
                $pictureClose = '</picture>';
            }
        }
    @endphp
    @if($isVideo)
        @php
            // Het echte type van het bestand. Een mov, m4v of 3gp blijft als mp4
            // aangeboden: browsers buiten Safari slaan een source met
            // video/quicktime over, terwijl ze de inhoud wel afspelen.
            $videoMime = strtolower((string) ($media->mime ?? ''));
            $videoType = str_starts_with($videoMime, 'video/') && ! in_array($videoMime, ['video/quicktime', 'video/x-m4v', 'video/3gpp', 'video/3gpp2'], true) ? $videoMime : 'video/mp4';
            $videoPoster = is_string($media->poster ?? null) ? $media->poster : '';
            // De afmetingen van het bestand zelf (geen formaat), en alleen
            // als de aanroeper ze niet opgaf: zo verspringt de pagina niet
            // zodra de video laadt en verandert er verder niets aan de maat.
            $videoWidth = ! $givenWidth && ! $givenHeight && is_numeric($media->width ?? null) && is_numeric($media->height ?? null) ? (int) $media->width : 0;
            $videoHeight = $videoWidth ? (int) $media->height : 0;
        @endphp
        <video {{ $attributes }}
               @if($controls) controls @endif
               @if($autoplay) autoplay playsinline @endif
               @if($muted) muted @endif
               @if($loop) loop @endif
               @if(! $autoplay) preload="metadata" @endif
               @if($videoPoster !== '') poster="{{ $videoPoster }}" @endif
               @if($videoWidth > 0 && $videoHeight > 0) width="{{ $videoWidth }}" height="{{ $videoHeight }}" @endif
               loading="{{ $loading }}">
            <source src="{{ $url }}" type="{{ $videoType }}">
            Your browser does not support the video tag.
        </video>
    @else
        {!! $pictureOpen !!}<img
            width="{{ $width ?: '' }}"
            height="{{ $height ?: '' }}"
            src="{{ $url }}"
            alt="{{ $alt }}"
            loading="{{ $loading }}"
            decoding="async"
            fetchpriority="{{ $fetchpriority }}"
            {{ $attributes }}
        >{!! $pictureClose !!}
    @endif
@endif
