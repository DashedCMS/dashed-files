<?php

namespace Dashed\DashedFiles\Classes\Svg;

use Throwable;
use DOMDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Een SVG uit de bibliotheek INLINE in de pagina, voor het component
 * <x-dashed-files::svg>. Inline draait een svg in de pagina zelf, dus de
 * eisen zijn strenger dan voor een bestand:
 *
 * - alleen een item dat bij de inname is opgeschoond (`svg_sanitized`);
 * - alleen uit het beheer of uit code (`upload`, `system`), nooit wat een
 *   bezoeker, een app of een extern adres aanleverde;
 * - bij het tekenen gaat de inhoud NOGMAALS door de opschoner, nu met het
 *   strengere profiel `inline` en een voorvoegsel voor de id's.
 *
 * Voldoet het item niet, of weigert de opschoner, dan geeft render() null
 * en valt het component terug op een gewone img.
 */
class InlineSvg
{
    public const SOURCES = ['upload', 'system'];

    /** Alleen deze attributen van de aanroeper komen op het svg-element. */
    public const ATTRIBUTES = ['class', 'width', 'height', 'role', 'aria-label', 'aria-hidden'];

    // Groter dan dit hoort niet in de html van elke pagina.
    public const MAX_BYTES = 512 * 1024;

    public const CACHE_DAYS = 30;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function render(int|string|null $mediaId, array $attributes = [], ?string $idPrefix = null): ?string
    {
        try {
            return $this->build($mediaId, $attributes, $idPrefix);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * De sleutel van de cache: het item, zijn inhoud, de versie van de
     * opschoner en het voorvoegsel. Een vervangen bestand of een nieuwe
     * opschoner geeft zo nooit de oude uitvoer.
     */
    public static function cacheKey(MediaItem $item, Media $file, string $prefix): string
    {
        $content = $item->getAttribute('checksum') ?: ($file->getKey() . '-' . $file->size . '-' . $file->updated_at?->getTimestamp());

        return 'dashed-files.inline-svg.' . $item->getKey() . '.' . $content . '.' . SvgSanitizer::VERSION . '.' . md5($prefix);
    }

    public static function eligible(?MediaItem $item, ?Media $file): bool
    {
        if (! $item || ! $file || strtolower((string) $file->mime_type) !== 'image/svg+xml') {
            return false;
        }

        // Een privé-svg komt nooit in de HTML van de site, ook niet inline.
        if (\Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($item) || \Dashed\DashedFiles\Classes\Access\MediaStorage::isPrivateDisk($file->disk)) {
            return false;
        }

        $version = $file->getCustomProperty('svg_sanitized');

        if (! is_numeric($version) || (int) $version < 1 || (int) $file->size > self::MAX_BYTES) {
            return false;
        }

        // `stock:pexels` is de bron `stock`.
        $source = (string) ($file->getCustomProperty('source') ?: $item->getAttribute('source'));

        return in_array(explode(':', $source, 2)[0], self::SOURCES, true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function build(int|string|null $mediaId, array $attributes, ?string $idPrefix): ?string
    {
        if (! is_int($mediaId) && ! (is_string($mediaId) && ctype_digit($mediaId))) {
            return null;
        }

        // Ook uit de prullenbak, net als een afbeelding: de site toont het tot het echt weg is.
        $item = app(MediaLibrary::class)->find((int) $mediaId, withTrashed: true);
        $file = $item?->file();

        if (! self::eligible($item, $file)) {
            return null;
        }

        $prefix = is_string($idPrefix) && preg_match('/^[A-Za-z][A-Za-z0-9_\-]{0,40}$/D', $idPrefix) ? $idPrefix : 'dm' . $item->getKey() . '-';

        // Een lege tekst in de cache betekent: de opschoner weigerde (of het
        // bestand is te groot). Dat is een uitspraak over de inhoud en mag
        // lang blijven staan, want de sleutel volgt de inhoud.
        $key = self::cacheKey($item, $file, $prefix);
        $clean = Cache::get($key);

        if (! is_string($clean)) {
            // Een leesfout (de schijf geeft niets, of gooit) is geen
            // uitspraak over de inhoud en wordt daarom nooit onthouden: de
            // volgende aanroep probeert het gewoon opnieuw.
            $raw = Storage::disk($file->disk)->get($file->getPathRelativeToRoot());

            if (! is_string($raw) || $raw === '') {
                return null;
            }

            $clean = strlen($raw) > self::MAX_BYTES ? '' : (string) app(SvgSanitizer::class)->sanitize($raw, SvgSanitizer::PROFILE_INLINE, $prefix);

            Cache::put($key, $clean, now()->addDays(self::CACHE_DAYS));
        }

        if ($clean === '') {
            return null;
        }

        return $this->withAttributes($clean, $attributes, $item, $file);
    }

    /**
     * Zet de toegestane attributen van de aanroeper op het svg-element.
     * Via de DOM: die schrijft elke waarde ge-escapet terug.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function withAttributes(string $svg, array $attributes, MediaItem $item, Media $file): ?string
    {
        $document = new DOMDocument();

        if (! @$document->loadXML($svg, LIBXML_NONET) || ! $document->documentElement || strtolower($document->documentElement->localName) !== 'svg') {
            return null;
        }

        $root = $document->documentElement;
        $given = [];

        foreach (self::ATTRIBUTES as $name) {
            $value = $attributes[$name] ?? null;

            if ($value === true) {
                $value = 'true';
            }

            if ((is_string($value) || is_int($value) || is_float($value)) && (string) $value !== '') {
                $given[$name] = mb_substr((string) $value, 0, 500);
            }
        }

        // Zonder afmetingen tekent een browser een inline svg op 300 bij
        // 150. Geeft de aanroeper niets en draagt het bestand ze niet zelf,
        // dan de bekende afmetingen.
        if (! isset($given['width']) && ! isset($given['height']) && ! $root->hasAttribute('width') && ! $root->hasAttribute('height')) {
            $width = $file->getCustomProperty('original_width');
            $height = $file->getCustomProperty('original_height');

            if (is_numeric($width) && is_numeric($height) && (int) $width > 0 && (int) $height > 0) {
                $given['width'] = (string) (int) $width;
                $given['height'] = (string) (int) $height;
            }
        }

        // Toegankelijkheid: de alt-tekst van het item als naam, anders is het decoratie.
        if (! isset($given['aria-label']) && ! isset($given['aria-hidden'])) {
            $alt = $item->localized('alt');

            if (filled($alt)) {
                $given['aria-label'] = mb_substr((string) $alt, 0, 500);
            } else {
                $given['aria-hidden'] = 'true';
            }
        }

        if (isset($given['aria-label']) && ! isset($given['role'])) {
            $given['role'] = 'img';
        }

        foreach ($given as $name => $value) {
            $root->setAttribute($name, $value);
        }

        $html = $document->saveXML($root);

        return is_string($html) && $html !== '' ? $html : null;
    }
}
