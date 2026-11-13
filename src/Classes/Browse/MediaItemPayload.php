<?php

namespace Dashed\DashedFiles\Classes\Browse;

use Throwable;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Classes\MediaConversions;
use Dashed\DashedFiles\Classes\Preview\GifPoster;
use Dashed\DashedFiles\Classes\ConversionDefinition;
use Dashed\DashedFiles\Classes\MediaPickerFileCache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Dashed\DashedFiles\Classes\Conversions\ConversionPump;

/**
 * Eén item zoals de beheerpagina en de kiezer het krijgen. Leest alleen wat
 * al geladen is (het item, zijn mediarij en zijn tags) en doet zelf geen
 * query's: de aanroeper laadt `media` en `tags` vooraf.
 */
final class MediaItemPayload
{
    public const SVG = 'image/svg+xml';

    // Een origineel boven deze grootte is nooit een miniatuur: dan liever geen plaatje dan een tegel van megabytes.
    public const MAX_ORIGINAL_THUMB_BYTES = 1_048_576;

    /**
     * @return array{id: int, name: string, file_name: ?string, mime: ?string, type: string, size: ?int, width: ?int, height: ?int, alt: ?string, folder_id: ?int, thumb: ?string, url: ?string, created_at: ?string, is_favorite: bool, tags: array<int, string>}
     */
    public static function for(MediaItem $item, bool $isFavorite = false, ?int $usageCount = null, ?int $purgeDays = null): array
    {
        $file = $item->file();

        // De spiegelkolommen eerst; wat daar nog leeg is (een item van voor de
        // aanvultaak) komt van de mediarij, die toch al geladen is.
        $mirror = $file ? MediaMirror::attributesFor($file) : [];
        $value = fn (string $key) => $item->getAttribute($key) ?? ($mirror[$key] ?? null);

        $mime = $value('mime_type');
        $type = self::typeOf($mime);
        $fileName = $value('file_name');
        // Een privé-item heeft geen adres op de site: het beheer opent het
        // bestand en zijn miniatuur via een ondertekende, kortlevende route.
        // Dat is rekenwerk (een HMAC per adres), geen query.
        $private = \Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($item);
        $url = match (true) {
            ! $file => null,
            $private => MediaLibrary::privateUrl($item),
            default => self::safely(fn () => $file->getUrl()),
        };
        // Fase 9b: de poster van een video of de miniatuur van een pdf.
        $kind = \Dashed\DashedFiles\Classes\Preview\MediaPreviews::kindOf($mime);
        $poster = $kind !== null && $file ? rescue(fn () => app(\Dashed\DashedFiles\Classes\Preview\MediaPreviews::class)->posterFor($item), null, false) : null;

        // Een geanimeerde gif toont in het beheer zijn eerste beeld; zolang
        // die er nog niet is (of hij niet te maken is) geldt het origineel,
        // tot de grens van een miniatuur.
        $gifPoster = $file && GifPoster::isGif($mime) ? self::gifPoster($item, $file) : null;
        // De beheerminiatuur: klein, voor tegels, lijst, mediaveld en kiezer.
        $small = $file && ! $gifPoster && $kind === null ? self::adminThumb($item, $file, $type, $mime, $private) : null;

        // Alleen een video of pdf draagt deze sleutels: voor elk ander item
        // blijft de payload precies wat hij was.
        $previewKeys = $kind === null ? [] : [
            'duration' => $kind === 'video' ? \Dashed\DashedFiles\Classes\Preview\PreviewNumbers::duration($item->getAttribute('duration')) : null,
            'pages' => $kind === 'pdf' ? \Dashed\DashedFiles\Classes\Preview\PreviewNumbers::pages($item->getAttribute('pages')) : null,
            'has_preview' => $poster !== null,
            'poster_custom' => (bool) ($poster['custom'] ?? false),
        ];

        return $previewKeys + [
            'id' => (int) $item->getKey(),
            'name' => filled($item->title) ? (string) $item->title : (string) ($fileName ?? ''),
            'file_name' => $fileName,
            'mime' => $mime,
            'type' => $type,
            'size' => self::int($value('size')),
            'width' => self::int($value('width')),
            'height' => self::int($value('height')),
            // De eerste taal: in het paneel is de taal van de applicatie die van de beheerder.
            'alt' => filled($item->alt_text) ? (string) $item->alt_text : null,
            'folder_id' => self::int($item->folder_id),
            'thumb' => match (true) {
                $kind !== null => $poster['url'] ?? null,
                $gifPoster !== null => $gifPoster['url'],
                $private => $file && in_array($type, ['image', 'svg'], true) ? MediaLibrary::privateUrl($item, null, 'thumb') : null,
                default => self::thumb($item, $file, $mime, $type, $url, self::int($value('size'))),
            },
            'url' => $url,
            'created_at' => $item->created_at?->toIso8601String(),
            'is_favorite' => $isFavorite,
            'tags' => $item->relationLoaded('tags') ? $item->tags->pluck('name')->sort()->values()->all() : [],
            // Null zolang de nulmeting van de gebruiksindex niet klaar is: dan is "0" geen uitspraak.
            'usage_count' => $usageCount,
            // Gelijke inhoud geeft dezelfde groep; de interface zet ze daarop bij elkaar.
            'duplicate_group' => filled($checksum = $item->getAttribute('checksum')) ? substr((string) $checksum, 0, 12) : null,
            'trashed' => ($deletedAt = $item->getAttribute('deleted_at')) !== null,
            'deleted_at' => $deletedAt?->toIso8601String(),
            // De dag waarop de prullenbak dit item definitief verwijdert.
            'purge_at' => MediaTrashOrigin::purgeAt($item, $purgeDays)?->toIso8601String(),
            // Weggegooid, maar de site heeft het sindsdien nog uitgeleverd of
            // de index kent er nog een verwijzing naar.
            'in_use_on_site' => $deletedAt !== null && ($item->getAttribute('trashed_served_at') !== null || (int) $usageCount > 0),
            // Fase 8: de talen van de installatie waarin de alt-tekst ontbreekt; alleen een afbeelding heeft er een.
            'alt_missing' => in_array($type, ['image', 'svg'], true) ? $item->missingAltLocales() : [],
        ] + ($deletedAt !== null && MediaTrashOrigin::codePurgeAt($item) !== null ? [
            // Alleen een item dat code weggooide (een taak, commando): gaat na de eigen termijn vanzelf weg.
            'trashed_by_system' => true,
        ] : []) + ($small !== null ? [
            // Alleen een afbeelding met een gegenereerde beheerminiatuur draagt deze sleutel.
            'thumb_small' => $small,
        ] : []) + ($gifPoster !== null ? [
            // Een geanimeerde gif met een poster: `thumb` is het eerste beeld, `url` blijft de animatie.
            'animated' => true,
        ] : []) + ($private ? [
            // Alleen een privé-item draagt deze sleutels: voor elk ander item blijft de payload wat hij was.
            'private' => true,
            'relocating' => filled($item->getAttribute('relocate_to')),
        ] : []);
    }

    public static function typeOf(?string $mime): string
    {
        $mime = (string) $mime;

        return match (true) {
            $mime === self::SVG => 'svg',
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            default => 'document',
        };
    }

    /**
     * De miniatuur zonder query en zonder schrijven: eerst de definitieve
     * url die MediaHelper al op het item bewaarde, dan wat er als gegenereerd
     * op de mediarij staat, en anders het origineel. Bewust niet via
     * getSingleMedia(): die schrijft per item zijn url terug en zet bij een
     * ontbrekend formaat een taak klaar, en dat is voor een pagina van 120
     * items geen vast aantal query's meer. De terugvalvolgorde: miniatuur,
     * `medium`, het origineel tot 1 MB, anders niets.
     */
    private static function thumb(MediaItem $item, ?Media $file, ?string $mime, string $type, ?string $url, ?int $size = null): ?string
    {
        if (! $file || ! in_array($type, ['image', 'svg'], true)) {
            return null;
        }

        // Een svg of gif heeft geen formaten: het origineel is de miniatuur.
        if ($type === 'svg' || $mime === 'image/gif') {
            return $url;
        }

        $stored = MediaLibrary::storedUrls($item->conversion_urls)['thumb']['url'] ?? null;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        // Met een gezet focuspunt staat de miniatuur onder een eigen naam.
        $focal = MediaLibrary::usesOwnEngine() ? $item->focalPoint() : null;
        MediaPickerFileCache::rememberFocal($item->getKey(), fn () => [$focal['x'] ?? null, $focal['y'] ?? null]);

        foreach (array_unique([\Dashed\DashedFiles\Classes\MediaConversions::thumbName($focal['x'] ?? null, $focal['y'] ?? null), 'thumb', 'medium']) as $name) {
            if (! $file->hasGeneratedConversion($name)) {
                continue;
            }

            // De registratie van formaten zoekt de kolom `conversions` per
            // item op (MediaConversions); het item is hier al geladen.
            MediaPickerFileCache::rememberConversions($item->getKey(), fn () => $item->conversions);

            if ($generated = self::safely(fn () => $file->getUrl($name))) {
                return $generated;
            }
        }

        // Geen formaat gegenereerd: het origineel alleen als het klein is.
        // Een foto van 8000x6000 is 12 MB voor een tegel van 300 pixels. Zonder
        // adres toont de tegel het pictogram van het bestandstype.
        $size ??= self::int($item->getAttribute('size') ?? $file->size);

        return self::originalOrNull($url, $size);
    }

    /** Het origineel als miniatuur, maar nooit boven een megabyte. */
    private static function originalOrNull(?string $url, ?int $size): ?string
    {
        return $size !== null && $size > self::MAX_ORIGINAL_THUMB_BYTES ? null : $url;
    }

    /**
     * De eerste-beeld-poster van een geanimeerde gif, of null: dan is het
     * een gewone gif (of de poster is er nog niet) en geldt `thumb()`. Een
     * gif die nog bekeken moet worden wordt aangevraagd.
     *
     * @return array{url: string}|null
     */
    private static function gifPoster(MediaItem $item, Media $file): ?array
    {
        $poster = rescue(fn () => app(\Dashed\DashedFiles\Classes\Preview\MediaPreviews::class)->posterFor($item), null, false);

        if ($poster !== null && GifPoster::available()) {
            return ['url' => $poster['url']];
        }

        if (GifPoster::isPending($item, $file)) {
            GifPoster::requestMissing((int) $item->getKey());
        }

        return null;
    }

    /**
     * Het adres van de beheerminiatuur (320 bij 320, met het focuspunt), of
     * null. Valt terug op die van een vorig focuspunt; ontbreekt hij nog
     * terwijl de gewone miniatuur er is, dan vraagt dit de pomp hem te
     * maken, voor dit ene item (nooit een nulmeting over de bibliotheek).
     */
    private static function adminThumb(MediaItem $item, Media $file, string $type, ?string $mime, bool $private): ?string
    {
        if ($type !== 'image' || ! MediaLibrary::usesOwnEngine() || ! MediaConversions::isConvertible((string) $mime)) {
            return null;
        }

        $focal = $item->focalPoint();
        $exact = MediaConversions::adminThumbName($focal['x'] ?? null, $focal['y'] ?? null);

        // De gewone miniatuur (of `medium`) is er al: dan werkt de pomp voor dit item, en ontbreekt alleen deze.
        if (! $file->hasGeneratedConversion($exact)
            && ($file->hasGeneratedConversion(MediaConversions::thumbName($focal['x'] ?? null, $focal['y'] ?? null)) || $file->hasGeneratedConversion('medium'))) {
            rescue(fn () => app(ConversionPump::class)->requestMissing((int) $item->getKey()), report: false);
        }

        foreach (array_unique([$exact, ConversionDefinition::ADMIN_THUMB]) as $name) {
            if (! $file->hasGeneratedConversion($name)) {
                continue;
            }

            if ($private) {
                return MediaLibrary::privateUrl($item, null, ConversionDefinition::ADMIN_THUMB);
            }

            MediaPickerFileCache::rememberFocal($item->getKey(), fn () => [$focal['x'] ?? null, $focal['y'] ?? null]);
            MediaPickerFileCache::rememberConversions($item->getKey(), fn () => $item->conversions);

            return self::safely(fn () => $file->getUrl($name));
        }

        return null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function safely(callable $callback): ?string
    {
        try {
            $value = $callback();

            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
