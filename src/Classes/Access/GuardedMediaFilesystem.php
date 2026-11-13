<?php

namespace Dashed\DashedFiles\Classes\Access;

use RuntimeException;
use Illuminate\Support\Facades\DB;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Dashed\DashedFiles\Classes\MediaCacheControl;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\RemoteFile;

/**
 * De schrijfkant van Spatie, met één controle ervoor: een bestand van een
 * mediabibliotheek-item wordt niet weggeschreven als de schijf van de
 * mediarij intussen is gewisseld. Een schrijver (de pomp, een losse
 * generatie) heeft de rij gelezen, is seconden tot minuten bezig met
 * genereren, en schrijft dan naar de schijf die hij las. Verhuisde het item
 * in die tijd naar de privé-schijf, dan zou het formaat alsnog op de
 * publieke schijf belanden. De controle is vlak voor het wegschrijven, dus
 * na het genereren.
 */
class GuardedMediaFilesystem extends Filesystem
{
    /**
     * Voor tests: aangeroepen vlak voor de controle, met de mediarij. Zo
     * laat een test de verhuizer precies tussen genereren en schrijven lopen.
     *
     * @var (callable(Media): void)|null
     */
    public static $beforeWrite = null;

    public function copyToMediaLibrary(string $pathToFile, Media $media, ?string $type = null, ?string $targetFileName = null): void
    {
        self::assertSameDisk($media);

        $this->withCacheControl($media, $type, fn () => parent::copyToMediaLibrary($pathToFile, $media, $type, $targetFileName));
    }

    public function copyToMediaLibraryFromRemote(RemoteFile $file, Media $media, ?string $type = null, ?string $targetFileName = null): void
    {
        $this->withCacheControl($media, $type, fn () => parent::copyToMediaLibraryFromRemote($file, $media, $type, $targetFileName));
    }

    /**
     * Geeft het bestand de `Cache-Control` van `MediaCacheControl` mee. Een
     * header die het project zelf met addCustomRemoteHeaders() zette, of die
     * op de mediarij staat, gaat voor: Spatie voegt die er later overheen.
     */
    private function withCacheControl(Media $media, ?string $type, \Closure $write): void
    {
        $previous = $this->customRemoteHeaders;
        $cache = MediaCacheControl::for($media, $type);

        if ($cache !== null) {
            $this->customRemoteHeaders = array_merge(['CacheControl' => $cache], $previous);
        }

        try {
            $write();
        } finally {
            $this->customRemoteHeaders = $previous;
        }
    }

    public static function assertSameDisk(Media $media): void
    {
        if (! $media->exists || ! in_array((string) $media->model_type, [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS], true) || ! BrowseSchema::hasAccess()) {
            return;
        }

        if (is_callable(self::$beforeWrite)) {
            (self::$beforeWrite)($media);
        }

        $row = DB::table($media->getTable())->where('id', $media->getKey())->first(['disk', 'conversions_disk']);

        if (! $row) {
            return;
        }

        if ((string) $row->disk !== (string) $media->disk || (string) ($row->conversions_disk ?: $row->disk) !== (string) ($media->conversions_disk ?: $media->disk)) {
            throw new RuntimeException("Media #{$media->getKey()} moved to another disk while this file was being generated; nothing was written.");
        }
    }
}
