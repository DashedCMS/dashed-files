<?php

namespace Dashed\DashedFiles\Filament\Forms\Components;

use Closure;
use Throwable;
use BadMethodCallException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Filament\Forms\Components\FileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Vervanger van het uploadveld uit het betaalde uploadpakket. Klantcode die
 * de oude klassenaam noemt krijgt via LegacyClassAliases deze klasse zodra
 * dat pakket weg is.
 *
 * Het grote verschil met Filaments FileUpload: het oude veld bewaarde het
 * pad NA de map (`<ULID>.pdf`, of de originele naam bij preserveFilenames),
 * niet het volle pad. Filament verwacht het volle pad; zonder de vertaling
 * hieronder gooit hydrateFiles() elke bestaande waarde weg (het bestand
 * "bestaat niet") en is de kolom na opslaan leeg.
 *
 * De staat in Livewire blijft dus in de relatieve vorm, net als in de
 * database. Alleen waar de schijf geraakt wordt (bestaat het, hoe groot, welke
 * url, waar opslaan) komt de map ervoor. Een waarde die al met de map begint
 * (oude rijen) gaat ongewijzigd door.
 *
 * Die vertaling is ook de beveiliging: elke waarde uit de Livewire-staat wordt
 * binnen de map opgelost, en wat daar via `..`, een absoluut pad, een
 * backslash, een nulbyte of een stream-wrapper uit zou kunnen, wordt niet
 * getoond en niet opgeslagen. Dat werkt ook zonder model, want in het portaal
 * heeft het formulier er geen en dekt Filaments preventFilePathTampering()
 * daar niets.
 */
class LegacyAdvancedFileUpload extends FileUpload
{
    /** Methodes van het oude veld waarvoor deze vervanger bewust geen tegenhanger heeft. */
    public const UNSUPPORTED = ['spatieMediaLibrary', 'collection', 'customProperties', 'filterMedia'];

    protected ?Closure $legacyFileUrlUsing = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Standaarden van het oude veld. Zichtbaarheid bewust niet: het oude
        // veld zette die niet, dus een bestand krijgt de standaard van de disk.
        $this->downloadable();
        $this->previewable();
        $this->deletable();
        $this->reorderable(false);

        $this->getUploadedFileNameForStorageUsing(static function (LegacyAdvancedFileUpload $component, TemporaryUploadedFile $file): string {
            if ($component->shouldPreserveFilenames()) {
                return basename(str_replace('\\', '/', $file->getClientOriginalName()));
            }

            // Een ULID is al 26 hoofdletters/cijfers (Crockford base32).
            return strtoupper((string) Str::ulid()) . '.' . $file->getClientOriginalExtension();
        });
    }

    public function getDiskName(): string
    {
        return filled($this->evaluate($this->diskName)) ? parent::getDiskName() : 'dashed';
    }

    /** De map zonder slashes aan het begin en eind, of null zonder map. */
    public function getLegacyDirectory(): ?string
    {
        $directory = trim((string) $this->getDirectory(), '/');

        return $directory === '' ? null : $directory;
    }

    /**
     * Of een waarde uit de staat een veilige naam is: geen `..`-segment, geen
     * backslash, geen nulbyte, geen stream-wrapper, en geen absoluut pad (een
     * voorloopslash mag alleen bij een oude rij die met de map begint).
     */
    public function isSafeStoredValue(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        if (str_contains($value, "\0") || str_contains($value, '\\') || str_contains($value, '://') || preg_match('/^[a-zA-Z]:/', $value)) {
            return false;
        }

        if (in_array('..', explode('/', $value), true)) {
            return false;
        }

        if (str_starts_with($value, '/')) {
            $directory = $this->getLegacyDirectory();

            return $directory !== null && str_starts_with(ltrim($value, '/'), $directory . '/');
        }

        return true;
    }

    /** Het pad op de disk voor een waarde uit de staat, of null als die niet deugt. */
    public function resolveStoredPath(mixed $value): ?string
    {
        if (! $this->isSafeStoredValue($value)) {
            return null;
        }

        $value = ltrim($value, '/');
        $directory = $this->getLegacyDirectory();

        if ($directory === null || str_starts_with($value, $directory . '/')) {
            return $value;
        }

        return $directory . '/' . $value;
    }

    public function hydrateFiles(): void
    {
        $shouldFetchFileInformation = $this->shouldFetchFileInformation();

        $this->rawState(
            array_filter(Arr::wrap($this->getRawState()), function (mixed $file) use ($shouldFetchFileInformation): bool {
                $path = $this->resolveStoredPath($file);

                if ($path === null) {
                    return false;
                }

                if (! $shouldFetchFileInformation) {
                    return true;
                }

                try {
                    return $this->getDisk()->exists($path);
                } catch (Throwable) {
                    return false;
                }
            }),
        );
    }

    public function getUploadedFile(string $file, string | array | null $storedFileNames): ?array
    {
        $path = $this->resolveStoredPath($file);

        if ($path === null) {
            return null;
        }

        $data = parent::getUploadedFile($path, null);

        if ($data === null) {
            return null;
        }

        $data['name'] = ($this->isMultiple() ? ($storedFileNames[$file] ?? null) : $storedFileNames) ?? basename($file);

        if ($this->legacyFileUrlUsing) {
            $url = $this->evaluate($this->legacyFileUrlUsing, ['file' => $file, 'path' => $path]);

            if (filled($url)) {
                $data['url'] = Str::sanitizeUrl($url);
            }
        }

        return $data;
    }

    public function saveUploadedFile(TemporaryUploadedFile $file): ?string
    {
        $path = parent::saveUploadedFile($file);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $directory = $this->getLegacyDirectory();

        // De opgeslagen waarde is het pad na de map, zoals het oude veld.
        return ($directory !== null && str_starts_with($path, $directory . '/'))
            ? substr($path, strlen($directory) + 1)
            : $path;
    }

    public function saveUploadedFiles(): void
    {
        $raw = Arr::wrap($this->getRawState());

        // Wat niet deugt gaat er eerst uit, zodat het nooit in de database komt.
        $safe = array_filter($raw, fn (mixed $file): bool => $file instanceof TemporaryUploadedFile || $this->isSafeStoredValue($file));
        $hasUploads = (bool) array_filter($safe, fn (mixed $file): bool => $file instanceof TemporaryUploadedFile);

        if (count($safe) !== count($raw)) {
            $this->rawState($safe);
        }

        if ($hasUploads) {
            parent::saveUploadedFiles();

            return;
        }

        // Niets nieuws: Filament roept hier toch callAfterStateUpdated() aan.
        // Een afterStateUpdated die direct opslaat (het portaal van viadaan)
        // zou zo zichzelf blijven aanroepen. Zonder upload verandert er niets,
        // dus valt er ook niets te melden.
        if (blank($safe)) {
            $this->rawState([]);
        }
    }

    public function isFilePathAuthorized(string $file, ?array $originalPaths = null): bool
    {
        return $this->isSafeStoredValue($file) && parent::isFilePathAuthorized($file, $originalPaths);
    }

    // ------------------------------------------------------------------
    // Methodes van het oude veld zonder tegenhanger in Filament. Indeling en
    // teksten van de dropzone zijn in Filaments veld anders opgebouwd; ze
    // worden aangenomen zodat bestaande code niet breekt.

    public function layout(string | Closure | null $layout): static
    {
        if ($layout === 'grid') {
            $this->panelLayout('grid');
        }

        return $this;
    }

    public function grid(array | int | Closure | null $columns = 2): static
    {
        return $this->panelLayout('grid');
    }

    public function gridDirection(mixed $direction): static
    {
        return $this;
    }

    public function filesColumns(mixed $columns): static
    {
        return $this->panelLayout('grid');
    }

    public function dropzoneColumnSpan(mixed $span): static
    {
        return $this;
    }

    public function filesColumnSpan(mixed $span): static
    {
        return $this->panelLayout('grid');
    }

    /** Livewire uploadt zelf; de grootte van een stuk is daar instelbaar, niet per veld. */
    public function chunkSize(mixed $size): static
    {
        return $this;
    }

    /** De tijdelijke disk is installatiebreed (UploadSecurity), niet per veld. */
    public function temporaryFileUploadDisk(mixed $disk): static
    {
        return $this;
    }

    public function temporaryFileUploadDirectory(mixed $directory): static
    {
        return $this;
    }

    public function dropzoneHeading(mixed $heading): static
    {
        return $this;
    }

    public function dropzoneDescription(mixed $description): static
    {
        return $this;
    }

    public function editable(bool | Closure $condition = true): static
    {
        return $this;
    }

    public function replaceable(bool | Closure $condition = true): static
    {
        return $this;
    }

    public function deleteAll(bool | Closure $condition = true): static
    {
        return $this;
    }

    public function maxFilesReachedMessage(mixed $message): static
    {
        return $this;
    }

    /**
     * Een eigen url voor een bestaand bestand. De closure krijgt `$file` (de
     * opgeslagen waarde) en `$path` (het pad op de disk).
     */
    public function getFileUrlUsing(?Closure $callback): static
    {
        $this->legacyFileUrlUsing = $callback;

        return $this;
    }

    public function __call(string $method, array $parameters): mixed
    {
        if (in_array($method, self::UNSUPPORTED, true) || str_contains(strtolower($method), 'multipart') || str_starts_with(strtolower($method), 's3')) {
            throw new BadMethodCallException(sprintf(
                'Method %s::%s() wordt door de vervanger van het oude uploadveld niet ondersteund.',
                static::class,
                $method,
            ));
        }

        return parent::__call($method, $parameters);
    }
}
