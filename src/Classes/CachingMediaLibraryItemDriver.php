<?php

namespace Dashed\DashedFiles\Classes;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Drivers\Driver;
use RalphJSmit\Filament\Explore\Enums\FileType;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\MediaPicker;

/**
 * MediaLibraryItemDriver die gevonden bestanden deelt met alle andere
 * drivers in hetzelfde verzoek, via MediaPickerFileCache.
 *
 * Laad deze klasse alleen na MediaPickerFileCache::isCompatible(): hij
 * overschrijft findFile() en findFiles() van het pakket, en als die ooit een
 * andere handtekening krijgen is het inladen zelf al een fatale fout.
 *
 * Alleen gevonden bestanden worden onthouden, en alleen bij een aanroep
 * zonder extra modifications. Met modifications (filteren, zoeken) doet de
 * driver precies wat het pakket doet.
 */
class CachingMediaLibraryItemDriver extends MediaLibraryItemDriver
{
    /**
     * Bouwt de driver voor een veld op dezelfde manier als de standaarddriver
     * van de MediaPicker, zodat alle instellingen uit MediaHelper::plugin()
     * (conversies, eigen acties) meekomen. Het paneel maakt zijn driver met
     * app(MediaLibraryItemDriver::class); die binding wijst hier heel even naar
     * deze klasse. Staat er al een eigen binding, of komt er toch iets anders
     * uit, dan blijft het bij de standaarddriver.
     */
    public static function forField(MediaPicker $component): Driver
    {
        $container = app();

        if ($container->bound(MediaLibraryItemDriver::class)) {
            return $component->getDefaultDriver();
        }

        $container->bind(MediaLibraryItemDriver::class, static::class);

        try {
            return $component->getDefaultDriver();
        } finally {
            $container->offsetUnset(MediaLibraryItemDriver::class);
        }
    }

    public function findFile(?FileType $fileType, string $key, array $modifications = []): ?FileData
    {
        if ($modifications !== []) {
            return parent::findFile($fileType, $key, $modifications);
        }

        $key = $this->normalizeKey($fileType, $key);

        if ($file = MediaPickerFileCache::get($this->sharedKey($key))) {
            $this->cacheFile($file);

            return $file;
        }

        $file = parent::findFile($fileType, $key);

        if ($file) {
            MediaPickerFileCache::put($this->sharedKey($file->getKey()), $file);
        }

        return $file;
    }

    public function findFiles(?FileType $fileType, array $keys, array $modifications = []): Collection
    {
        if ($modifications !== []) {
            return parent::findFiles($fileType, $keys, $modifications);
        }

        // Zelfde volgorde als het pakket: eerst wat al bekend is, in de
        // volgorde van de sleutels, daarna wat de database teruggeeft.
        $files = collect();
        $missing = [];

        foreach ($keys as $key) {
            $key = $this->normalizeKey($fileType, $key);

            if ($file = MediaPickerFileCache::get($this->sharedKey($key))) {
                $this->cacheFile($file);
                $files->push($file);

                continue;
            }

            $missing[] = $key;
        }

        if ($missing === []) {
            return $files;
        }

        $found = parent::findFiles($fileType, $missing);

        foreach ($found as $file) {
            MediaPickerFileCache::put($this->sharedKey($file->getKey()), $file);
        }

        return $files->merge($found);
    }

    protected function normalizeKey(?FileType $fileType, string $key): string
    {
        if ($fileType && ! Str::contains($key, ':')) {
            return match ($fileType) {
                FileType::Folder => 'media-library-folder:' . $key,
                FileType::File => 'media-library-item:' . $key,
            };
        }

        return $key;
    }

    protected function sharedKey(string $key): string
    {
        // Met tenancy hoort de tenant bij de sleutel, anders zou een bestand
        // van de ene tenant bij de andere uit de cache kunnen komen.
        if ($this->hasTenancy()) {
            return 'tenant:' . $this->getTenant()->getKey() . ':' . $key;
        }

        return $key;
    }
}
