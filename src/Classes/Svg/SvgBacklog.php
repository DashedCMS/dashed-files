<?php

namespace Dashed\DashedFiles\Classes\Svg;

use Throwable;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Illuminate\Contracts\Filesystem\Filesystem;
use Dashed\DashedFiles\Classes\Ingest\MediaChecksums;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * SVG's die van voor de opschoner zijn, of via de oude kiezer binnenkwamen:
 * tellen, opschonen en terugschrijven. Op een lokale publieke schijf kan
 * zo'n bestand, rechtstreeks geopend, een script uitvoeren op het domein
 * van de site.
 */
class SvgBacklog
{
    // Groter dan dit leest de opschoner niet in.
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Elke svg-mediarij van de bibliotheek, in elke collectie: ook het
     * bewaarde bestand van een vervangen item staat nog op de schijf.
     */
    public function query(): Builder
    {
        return Media::query()
            ->whereIn('model_type', [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS])
            ->where(fn (Builder $query) => $query
                ->where('mime_type', 'like', '%svg%')
                ->orWhere('file_name', 'like', '%.svg')
                ->orWhere('file_name', 'like', '%.svgz'));
    }

    public static function isCurrent(Media $media): bool
    {
        $version = $media->getCustomProperty('svg_sanitized');

        return is_numeric($version) && (int) $version >= SvgSanitizer::VERSION;
    }

    /** Het aantal svg's zonder vlag of met een oudere versie van de opschoner. */
    public function countUnsanitized(): int
    {
        $count = 0;

        // De vlag staat in json; tellen in PHP houdt dit gelijk op elke database.
        $this->query()->select(['id', 'custom_properties'])->chunkById(500, function ($rows) use (&$count) {
            foreach ($rows as $media) {
                if (! self::isCurrent($media)) {
                    $count++;
                }
            }
        });

        return $count;
    }

    /**
     * Schoont één mediarij op. `clean`: was al in orde. `sanitized`:
     * opgeschoond (bij $write ook teruggeschreven). `refused`: de opschoner
     * weigert het bestand; het blijft staan. `unreadable`: niet te lezen.
     */
    public function sanitize(Media $media, bool $write): string
    {
        if (self::isCurrent($media)) {
            return 'clean';
        }

        try {
            $disk = Storage::disk($media->disk);
            $path = $media->getPathRelativeToRoot();

            if (! $disk->exists($path) || $disk->size($path) > self::MAX_BYTES) {
                return $disk->exists($path) ? 'refused' : 'unreadable';
            }

            $original = (string) $disk->get($path);
        } catch (Throwable) {
            return 'unreadable';
        }

        $sanitizer = app(SvgSanitizer::class);
        $clean = rescue(fn () => $sanitizer->sanitize($original, 'file'), null, false);

        if ($clean === null) {
            return 'refused';
        }

        if (! $write) {
            return 'sanitized';
        }

        try {
            // Het type mee (anders raadt een objectopslag het uit de inhoud) en de zichtbaarheid die het bestand al had.
            $disk->put($path, $clean, \Dashed\DashedFiles\Classes\MediaCacheControl::svgWriteOptions($media->disk, $this->visibility($disk, $path)));
        } catch (Throwable $exception) {
            report($exception);

            return 'unreadable';
        }

        $media->setCustomProperty('svg_sanitized', SvgSanitizer::VERSION);

        if ($dimensions = $sanitizer->dimensions($clean)) {
            $media->setCustomProperty('original_width', $dimensions['width']);
            $media->setCustomProperty('original_height', $dimensions['height']);
        }

        $media->mime_type = 'image/svg+xml';
        $media->size = strlen($clean);
        $media->saveQuietly();

        // Alleen het huidige bestand van een item bepaalt zijn grootte en vingerafdruk.
        if ($media->collection_name === MediaItem::COLLECTION && ($item = MediaItem::fromMedia($media))) {
            if (app(MediaChecksums::class)->available()) {
                MediaItem::withoutGlobalScopes()->toBase()->where('id', $item->getKey())->update([
                    'size' => strlen($clean),
                    'checksum' => hash('sha256', $clean),
                ]);
            }

            app(MediaLibrary::class)->syncMirror($item);
            app(MediaLibrary::class)->invalidate($item, 'sanitized');
        }

        return 'sanitized';
    }

    /** Of de schijf van de bibliotheek een lokale map is die de webserver rechtstreeks uitserveert. */
    public static function diskIsLocalPublic(string $disk = 'dashed'): bool
    {
        $config = (array) config('filesystems.disks.' . $disk, []);

        return ($config['driver'] ?? null) === 'local' && ! empty($config['url']);
    }

    public static function diskRoot(string $disk = 'dashed'): ?string
    {
        $root = config('filesystems.disks.' . $disk . '.root');

        return is_string($root) && $root !== '' ? $root : null;
    }

    /** Of de header die scripts in een direct geopende svg uitzet voor de schijf geldt. */
    public static function headerPresent(string $disk = 'dashed'): bool
    {
        $class = 'Dashed\\DashedCore\\Classes\\UploadSecurity';
        $root = self::diskRoot($disk);

        return $root !== null && class_exists($class) && method_exists($class, 'svgHeadersPresent') && $class::svgHeadersPresent($root);
    }

    private function visibility(Filesystem $disk, string $path): string
    {
        try {
            return $disk->getVisibility($path) === 'private' ? 'private' : 'public';
        } catch (Throwable) {
            return 'private';
        }
    }
}
