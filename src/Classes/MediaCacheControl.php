<?php

namespace Dashed\DashedFiles\Classes;

use Illuminate\Support\Arr;
use Dashed\DashedFiles\Classes\Access\MediaStorage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * De `Cache-Control` die een mediabestand krijgt als het naar de schijf
 * gaat. Alleen voor nieuwe bestanden: wat al op een objectopslag staat houdt
 * zijn eigen header tot iemand het opnieuw schrijft.
 *
 * Immutable mag alleen waar een pad nooit andere bytes krijgt:
 *
 * - Het origineel staat onder `{uuid van de mediarij}/{bestandsnaam}`. Een
 *   vervanging, een bewerking, een nieuwe voorvertoning en een verhuizing
 *   naar een andere map maken een nieuwe rij met een nieuwe uuid, dus een
 *   nieuw pad. Het origineel van die rij wordt nooit overschreven.
 * - Uitzondering: een svg. De opschoner (`SvgBacklog`, `GuardMediaAddedOutsideIngest`)
 *   schrijft hem op hetzelfde pad opnieuw weg, dus een svg is nooit immutable.
 * - Een formaat (`conversions/`) en een responsive beeld (`responsive/`)
 *   staan wel op een vast pad per naam, en dat pad kan andere bytes krijgen:
 *   `media-library:regenerate --force`, de herstelrondes (heighten), een
 *   AVIF-controle die het bestand opnieuw maakt, of een andere breedte
 *   achter een vaste naam als `medium`. Die krijgen een kortere termijn met
 *   `must-revalidate`, zodat een browser of CDN na afloop controleert in
 *   plaats van blind te blijven tonen.
 *
 * Een privé-bestand krijgt hier niets: dat gaat nooit rechtstreeks naar een
 * bezoeker maar langs de beveiligde route, en een `public` header op een
 * privé-object zou een tussenlaag kunnen laten bewaren wat niet bewaard mag
 * worden.
 */
final class MediaCacheControl
{
    public const ORIGINAL = 'public, max-age=31536000, immutable';

    public const DERIVED = 'public, max-age=604800, must-revalidate';

    private const DERIVED_TYPES = ['conversions', 'responsiveImages'];

    /** De header voor een bestand van deze mediarij, of null als de instelling van het project moet gelden. */
    public static function for(Media $media, ?string $type): ?string
    {
        $derived = in_array($type, self::DERIVED_TYPES, true);
        $disk = $derived ? ($media->conversions_disk ?: $media->disk) : $media->disk;

        if (MediaStorage::isPrivateDisk((string) $disk)) {
            return null;
        }

        if ($derived) {
            return self::setting('conversion', self::DERIVED);
        }

        return self::isSvg((string) $media->mime_type, (string) $media->file_name)
            ? self::setting('conversion', self::DERIVED)
            : self::setting('original', self::ORIGINAL);
    }

    /** De header voor een bestand dat naar de publieke schijf verhuist, uit zijn pad. */
    public static function forPath(string $path, string $mime = ''): ?string
    {
        if (str_contains($path, '/conversions/') || str_contains($path, '/responsive/')) {
            return self::setting('conversion', self::DERIVED);
        }

        return self::isSvg($mime, $path)
            ? self::setting('conversion', self::DERIVED)
            : self::setting('original', self::ORIGINAL);
    }

    /**
     * De opties voor een svg die opnieuw wordt weggeschreven: het type en de
     * zichtbaarheid die het bestand had, en alleen een publieke header als
     * het bestand echt publiek is (niet op de privé-schijf, niet met
     * zichtbaarheid privé). Nooit immutable.
     *
     * @return array<string, string>
     */
    public static function svgWriteOptions(?string $disk, string $visibility): array
    {
        $options = ['ContentType' => 'image/svg+xml'];

        if ($visibility === 'public' && ! MediaStorage::isPrivateDisk($disk) && ($cache = self::setting('conversion', self::DERIVED)) !== null) {
            $options['CacheControl'] = $cache;
        }

        return $options + ['visibility' => $visibility];
    }

    private static function isSvg(string $mime, string $name): bool
    {
        return str_contains(strtolower($mime), 'svg') || strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'svg';
    }

    /**
     * De waarde uit `dashed-files.cache.<sleutel>`. Een sleutel die er staat
     * met een lege waarde zet het uit: dan geldt `media-library.remote.extra_headers`.
     */
    private static function setting(string $key, string $default): ?string
    {
        $cache = config('dashed-files.cache');

        if (! is_array($cache) || ! Arr::exists($cache, $key)) {
            return $default;
        }

        $value = $cache[$key];

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
