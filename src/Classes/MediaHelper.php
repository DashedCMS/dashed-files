<?php

namespace Dashed\DashedFiles\Classes;

use Illuminate\Support\Collection;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedFiles\Models\MediaItem;
use Spatie\MediaLibrary\Conversions\Conversion;
use Dashed\DashedFiles\Services\AiImageGenerator;
use Dashed\DashedFiles\Services\AiImageOperations;
use Dashed\DashedFiles\Classes\Ingest\IngestPolicy;
use Dashed\DashedFiles\Classes\Ingest\IncomingMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Dashed\DashedFiles\Filament\Actions\AiGenerateImageAction;
use Dashed\DashedFiles\Filament\Actions\AiMediaLibraryActions;
use Dashed\DashedFiles\Jobs\RegenerateMediaLibraryConversions;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidConversion;
use Dashed\DashedFiles\Filament\Forms\Components\MediaField;
use Dashed\DashedFiles\Filament\Forms\Components\UploadableMediaPicker;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\MediaPicker;

class MediaHelper
{
    // Zolang een formaat nog niet gegenereerd is (of een item niet bestaat)
    // is het antwoord voorlopig en mag het niet voor altijd de cache in.
    private const PROVISIONAL_TTL = 600;

    // Wachttijd tussen twee genereertaken voor hetzelfde bestand; elke
    // poging verdubbelt hem tot het maximum, zodat een bestand dat nooit
    // lukt niet bij elke verlopen cache opnieuw een taak kost.
    private const REGENERATE_WAIT = 300;

    private const REGENERATE_MAX_WAIT = 86400;

    /**
     * Per item en formaatnaam wat ook in de cache staat. Arrays en geen
     * objecten: elke aanroep krijgt zijn eigen object, zodat een aanroeper
     * die het resultaat aanpast dat niet voor de volgende doet.
     *
     * @var array<int, array<string, array|string>>
     */
    private static array $memoryCache = [];

    /**
     * Het mediaveld voor een formulier. Op de eigen motor het eigen veld
     * (MediaField), anders de kiezer van het pakket; beide kennen dezelfde
     * methodes, zodat wie hierop doorketent niets van de motor hoeft te weten.
     */
    public function field($name = 'image', $label = 'Afbeelding', bool $required = false, bool $multiple = false, bool $isImage = false, null|int|string $defaultFolder = null): Field
    {
        if (MediaLibrary::usesOwnEngine()) {
            $field = MediaField::make($name)
                ->label($label)
                ->required($required)
                ->multiple($multiple)
                ->downloadable()
                ->reorderable();

            if ($isImage) {
                $field->acceptedFileTypes(['image/*']);
            }

            if ($defaultFolder) {
                // Een pad wordt pas opgezocht (en zo nodig aangemaakt) als
                // iemand het venster opent of uploadt, niet per render.
                $field->defaultFolder($defaultFolder);
            }

            // Fase 11: dezelfde knop, maar hij opent nu het keuzevenster op het
            // tabblad AI. Het genereren loopt daar via de wachtrij in plaats
            // van tot drie minuten in het verzoek van de beheerder.
            $field->hintAction(
                AiGenerateImageAction::opener()->visible(fn () => AiGenerateImageAction::sourceAvailableFor($field))
            );

            return $field;
        }

        // De picker uit de mediabibliotheek, plus slepen-om-te-uploaden en de
        // knop "Uploaden" naast het label; de modal blijft er voor kiezen.
        $mediaPicker = UploadableMediaPicker::make($name)
            ->label($label)
            ->required($required)
            ->multiple($multiple)
            ->downloadable()
            ->reorderable();

        // Elk veld een eigen driver (die houdt per veld instellingen bij), maar
        // gevonden bestanden gedeeld over het hele verzoek. Zonder dit vroeg een
        // grote pagina bij elke opslag dezelfde media tientallen keren op.
        if (MediaPickerFileCache::isCompatible()) {
            $mediaPicker->driver(fn (MediaPicker $component) => CachingMediaLibraryItemDriver::forField($component));
        }

        if ($isImage) {
            $mediaPicker->acceptedFileTypes(['image/*']);
        }

        if ($defaultFolder) {
            if (is_string($defaultFolder)) {
                $defaultFolder = $this->getFolderId($defaultFolder);
            }

            $mediaPicker->defaultFolder(MediaLibraryFolder::find($defaultFolder));
        }

        $mediaPicker->hintAction(
            AiGenerateImageAction::make()->visible(fn () => AiImageGenerator::isConfigured())
        );

        return $mediaPicker;
    }

    /**
     * Van wat er in een mediaveld of in opgeslagen inhoud kan staan naar één
     * waarde: een id als int, een geplakt adres of andere tekst ongewijzigd,
     * of null bij niets. Kent ook de versleutelde sleutel van de kiezer van
     * het pakket, voor een tabblad dat open stond tijdens het omschakelen.
     */
    public function normalizeId(mixed $value): int|string|null
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value)) {
            return $value >= 1 && floor($value) === $value && $value <= PHP_INT_MAX ? (int) $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return strlen($value) <= 18 && (int) $value > 0 ? (int) $value : null;
        }

        // Een sleutel is base64; een adres heeft bijna altijd een punt of
        // dubbele punt en is dus geen sleutel. Dat scheelt een ontsleutelpoging.
        if (! preg_match('#[^A-Za-z0-9+/=]#', $value) && ($id = $this->decryptPickerKey($value)) !== null) {
            return (int) $id > 0 ? (int) $id : null;
        }

        return $value;
    }

    public function plugin()
    {
        return FilamentMediaLibrary::make()
            ->navigationGroup(__('Content'))
            ->navigationIcon('heroicon-o-camera')
            ->activeNavigationIcon('heroicon-s-camera')
            ->acceptPdf()
            ->acceptVideo()
            ->driver(modifyDriverUsing: function (MediaLibraryItemDriver $driver) {
                $driver
                    ->conversions()
                    ->conversionResponsive(enabled: true, modifyUsing: function (Conversion $conversion) {
                        return $conversion->format('webp');
                    })
                    ->conversionMedium(enabled: false, width: 800)
                    ->conversionSmall(enabled: false, width: 400)
                    ->conversionThumb(enabled: true, width: 600, height: 600, modifyUsing: function (Conversion $conversion) {
                        return $conversion->format('webp');
                    });

                $driver->registerConversions(function (MediaLibraryItem $mediaLibraryItem, ?Media $media = null) {
                    MediaConversions::register($mediaLibraryItem, $media);
                });

                if (AiImageOperations::isConfigured()) {
                    $driver
                        ->pushFileActions(AiMediaLibraryActions::all())
                        ->pushFileInfoActions(AiMediaLibraryActions::all())
                        ->pushBulkActions(AiMediaLibraryActions::bulk())
                        ->pushEmptyStateActions([
                            AiMediaLibraryActions::generate(),
                        ]);
                }
            })
            ->slug('media-browser');
    }

    /**
     * De in-process cache leeft zolang het proces leeft; een testsuite of
     * een langlopende worker die een ander item onder hetzelfde id ziet,
     * moet hem kunnen legen.
     */
    public static function flushMemoryCache(): void
    {
        static::$memoryCache = [];
        self::$servedNoted = [];
        // De onthouden generatie hoort bij dezelfde staat: een ander proces
        // kan hem intussen hebben opgehoogd.
        MediaLibrary::forgetGeneration();
    }

    public static function forgetInMemory(int $mediaId): void
    {
        unset(static::$memoryCache[$mediaId]);
    }

    private function decryptPickerKey(string $value): ?string
    {
        $key = \Dashed\DashedFiles\Classes\Legacy\LegacyPickerKey::decrypt($value);

        if (! is_string($key)) {
            return null;
        }

        // De mediabibliotheek zet een soortvoorvoegsel voor het id
        // ("media-library-item:2039"); een map ("media-library-folder:7")
        // is geen media en telt niet.
        if (str_contains($key, ':') && ! str_starts_with($key, 'media-library-item:')) {
            return null;
        }

        $id = (string) str($key)->afterLast(':');

        return ctype_digit($id) ? $id : null;
    }

    /**
     * @return $this
     *
     * @deprecated
     */
    public function getSingleImage(null|int|string|array $mediaId, array|string $conversion = 'medium')
    {
        return $this->getSingleMedia($mediaId, $conversion);
    }

    public function getSingleMedia(null|int|string|array $mediaId, array|string $conversion = 'medium')
    {
        if (! $mediaId) {
            return '';
        }

        if (is_array($mediaId)) {
            $mediaId = $mediaId[0] ?? null;
        }

        if (is_string($mediaId) && ! ctype_digit($mediaId)) {
            // De Livewire-staat van een mediakiezer draagt geen id maar een
            // versleutelde sleutel (FileData::getKeyHash()); wie de
            // formulierstaat rechtstreeks rendert, zoals een preview, komt
            // daarmee hier. Alles wat geen sleutel is, is een geplakte URL
            // of tekst en gaat ongewijzigd terug.
            $mediaId = $this->decryptPickerKey($mediaId) ?? $mediaId;

            if (! ctype_digit($mediaId)) {
                return $mediaId;
            }
        }

        if (! $mediaId) {
            return '';
        }

        $mediaId = (int) $mediaId;
        // Een benoemd formaat is een alias voor de arrayvorm: dezelfde naam, hetzelfde bestand.
        $conversion = MediaPresets::expand($conversion);
        $conversionName = $this->getConversionName($conversion) ?: 'original';

        if (! isset(static::$memoryCache[$mediaId][$conversionName])) {
            $key = MediaLibrary::cacheKey($mediaId, $conversionName);
            $payload = Cache::get($key);

            if ($payload === null) {
                // Ook uit de prullenbak: de site blijft een weggegooid bestand tonen tot het definitief weg is.
                $payload = $this->resolveAndCache(app(MediaLibrary::class)->find($mediaId, withTrashed: true), $conversionName, $conversion, $mediaId);
            }

            static::$memoryCache[$mediaId][$conversionName] = $payload;
        }

        $this->noteTrashedServed($mediaId, static::$memoryCache[$mediaId][$conversionName]);

        return $this->present(static::$memoryCache[$mediaId][$conversionName]);
    }

    /** @var array<int, true> per proces: dit weggegooide item is al genoteerd */
    private static array $servedNoted = [];

    /**
     * Een weggegooid bestand dat de site toch nog uitlevert wordt daarmee
     * gemarkeerd (`trashed_served_at`): niets ruimt het dan automatisch op,
     * en de prullenbak toont "nog in gebruik op de site". Voor een gewoon
     * item kost dit niets: de vlag staat alleen in de payload van een item
     * in de prullenbak. Hooguit één keer per dag per item, en nooit een fout
     * voor de bezoeker.
     */
    private function noteTrashedServed(int $mediaId, mixed $payload): void
    {
        if (! is_array($payload) || empty($payload['trashed']) || isset(self::$servedNoted[$mediaId])) {
            return;
        }

        self::$servedNoted[$mediaId] = true;

        rescue(function () use ($mediaId) {
            if (Cache::add(MediaLibrary::servedKey($mediaId), true, 86400) && \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasServed()) {
                MediaItem::withoutGlobalScopes()->toBase()->where('id', $mediaId)->whereNotNull('deleted_at')->update(['trashed_served_at' => now()]);
            }
        }, report: false);
    }

    public static function forgetServedNotes(): void
    {
        self::$servedNoted = [];
    }

    /**
     * Preload media URLs for multiple IDs in a single Redis MGET + single DB query.
     */
    public function preloadMediaUrls(array $mediaIds, array|string $conversion = 'medium'): void
    {
        $conversion = MediaPresets::expand($conversion);
        $conversionName = $this->getConversionName($conversion) ?: 'original';

        $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds))));

        $keysToFetch = [];
        foreach ($mediaIds as $id) {
            if (! isset(static::$memoryCache[$id][$conversionName])) {
                $keysToFetch[$id] = MediaLibrary::cacheKey($id, $conversionName);
            }
        }

        if (empty($keysToFetch)) {
            return;
        }

        $values = Cache::many(array_values($keysToFetch));

        $missingIds = [];
        foreach ($keysToFetch as $id => $key) {
            if (($values[$key] ?? null) !== null) {
                static::$memoryCache[$id][$conversionName] = $values[$key];
                $this->noteTrashedServed($id, $values[$key]);
            } else {
                $missingIds[] = $id;
            }
        }

        if (empty($missingIds)) {
            return;
        }

        $items = app(MediaLibrary::class)->findMany($missingIds, withTrashed: true);

        // Alleen wie nog geen opgeslagen url heeft, heeft zijn bestand nodig;
        // in één query, anders kost elk item er een.
        $items->filter(fn (MediaItem $item) => ! isset($this->getConversionData($item)[$conversionName]))->load('media');

        foreach ($missingIds as $id) {
            static::$memoryCache[$id][$conversionName] = $this->resolveAndCache($items->get($id), $conversionName, $conversion, $id);
            $this->noteTrashedServed($id, static::$memoryCache[$id][$conversionName]);
        }
    }

    /**
     * Laadt de media van een lijst content-blokken in één cacheronde voor,
     * zodat de afbeeldingen die daarna renderen uit het geheugen komen in
     * plaats van elk een eigen Cache::get (bij Redis een round trip per
     * beeld). Verandert niets aan wat uitgeleverd wordt: het vult alleen
     * dezelfde geheugencache die getSingleMedia() zelf ook vult, voor de
     * formaten uit `dashed-files.preload_conversions` (standaard `medium`).
     * Een id dat hier niet bij zit, of een ander formaat, gaat gewoon zijn
     * eigen weg. Nooit een fout voor de bezoeker.
     */
    public function preloadContentMedia(mixed $content): void
    {
        if (! config('dashed-files.preload_content_media', true) || ! is_array($content) || $content === []) {
            return;
        }

        $ids = $this->mediaIdsIn($content);

        if ($ids === []) {
            return;
        }

        $conversions = array_filter((array) config('dashed-files.preload_conversions', ['medium']), 'is_string');

        rescue(function () use ($ids, $conversions) {
            foreach ($conversions ?: ['medium'] as $conversion) {
                $this->preloadMediaUrls($ids, $conversion);
            }
        }, report: false);
    }

    /**
     * De media-id's in blokdata, zonder het schema van de blokken te kennen:
     * een geheel getal (of cijfertekst) onder een sleutel die naar zijn naam
     * een mediaveld is (dezelfde lijst als de gebruiksindex voor een gok), of
     * een lijst daarvan. Alles dieper in de data wordt ook doorzocht. Een
     * overbodig id kost een regel in dezelfde cacheaanvraag; een gemist id
     * gaat gewoon de gewone weg.
     *
     * @return array<int, int>
     */
    public function mediaIdsIn(array $content, int $limit = 500): array
    {
        $found = [];
        $keys = \Dashed\DashedFiles\Classes\Usage\MediaReferenceExtractor::GUESS_KEYS;

        $asId = function (mixed $value): ?int {
            if (is_int($value) && $value > 0) {
                return $value;
            }

            return is_string($value) && ctype_digit($value) && strlen($value) <= 12 && (int) $value > 0 ? (int) $value : null;
        };

        $walk = function (array $data, int $depth) use (&$walk, &$found, $keys, $asId, $limit): void {
            if ($depth > 8) {
                return;
            }

            foreach ($data as $key => $value) {
                if (count($found) >= $limit) {
                    return;
                }

                if (is_string($key) && in_array($key, $keys, true)) {
                    foreach (is_array($value) ? $value : [$value] as $entry) {
                        if (is_array($entry)) {
                            $walk($entry, $depth + 1);
                        } elseif (($id = $asId($entry)) !== null) {
                            $found[$id] = $id;
                        }
                    }

                    continue;
                }

                if (is_array($value)) {
                    // Een verwijzing naar een globaal blok is geen media.
                    if (($value['type'] ?? null) === 'globalBlock') {
                        continue;
                    }

                    $walk($value, $depth + 1);
                }
            }
        };

        $walk($content, 0);

        return array_values($found);
    }

    public function getMultipleMedia(array $mediaIds, string $conversion = 'medium'): ?Collection
    {
        $this->preloadMediaUrls($mediaIds, $conversion);

        return collect($mediaIds)->map(fn ($id) => $this->getSingleMedia($id, $conversion));
    }

    public function getConversionName(string|array $conversion, $isChild = false): string
    {
        return ConversionDefinition::nameOf($conversion, (bool) $isChild);
    }

    /**
     * Het pad van de mappen boven deze map, zonder de map zelf.
     */
    public function getFolderPath(?int $folderId = null): string
    {
        $segments = app(MediaLibrary::class)->folder($folderId)?->pathSegments() ?? [];
        array_pop($segments);

        return implode('/', $segments);
    }

    public function getFolderId($folder): int
    {
        return app(MediaLibrary::class)->folderIdForPath((string) $folder);
    }

    public function getFileId(string $file, ?int $folderId = null): ?int
    {
        $library = app(MediaLibrary::class);
        $id = $library->findInFolderByFileName(basename($file), $folderId);

        if ($id === null || basename($file) === $file) {
            return $id;
        }

        // Een pad met mappen erin telt alleen als het opgeslagen pad er ook
        // op eindigt. Zonder die eis zou een tweede bron met dezelfde
        // bestandsnaam (een/foto.jpg, twee/foto.jpg) het eerste bestand
        // terugkrijgen in plaats van zijn eigen upload.
        $storedPath = (string) $library->find($id)?->file()?->getPath();

        return str_ends_with($storedPath, $file) ? $id : null;
    }

    public function uploadFromPath($path, $folder, bool $isExternalImage = false): ?int
    {
        try {
            // Een leeg pad of alleen schuine strepen is de hoofdmap.
            $folderId = trim((string) $folder, "/ \t\n") === '' ? null : $this->getFolderId($folder);
        } catch (\Exception $e) {
            return null;
        }

        $path = (string) $path;

        // De controle op naam binnen de map blijft naast de checksum bestaan:
        // aanroepers rekenen erop dat dezelfde naam in dezelfde map hetzelfde
        // item is, en dat hetzelfde bestand in een andere map een eigen item
        // wordt. Daarom hieronder ook inname met 'create'.
        if ($existingFile = $this->getFileId($path, $folderId)) {
            return $existingFile;
        }

        $library = app(MediaLibrary::class);
        $incoming = null;

        try {
            if ($isExternalImage && ($ownPath = $this->pathOnOwnDisk($path)) !== null) {
                // Een url naar de eigen schijf is geen download: de wachter
                // voor url's zou een lokale http-schijf terecht weigeren.
                if ($existingFile = $this->getFileId(basename($ownPath), $folderId)) {
                    return $existingFile;
                }

                // Dit pad wordt met de sleutels van de server gelezen. Wat op
                // de schijf niet publiek is (een factuur) mag via deze weg
                // geen publiek bibliotheekitem worden.
                if (! $this->isPublicOnOwnDisk($ownPath)) {
                    throw new MediaRejectedException(__('Dit bestand is niet publiek en kan niet worden overgenomen.'));
                }

                $isExternalImage = false;
                $path = $ownPath;
            }

            if (! $isExternalImage) {
                $incoming = IncomingMedia::fromDisk('dashed', $path, 'system', $folderId);

                // ingest() bewaart onder de naam die bij de inhoud hoort
                // (foto.png met een jpeg erin wordt foto.jpg). Zonder ook
                // daarop te zoeken komt zo'n bestand bij elke run opnieuw binnen.
                $fileName = $library->storedFileName($incoming);

                if ($fileName !== basename($path) && ($existingFile = $this->getFileId($fileName, $folderId))) {
                    @unlink($incoming->localPath);

                    return $existingFile;
                }

                return $library->ingest($incoming, 'create')->item->id;
            }

            // Bron `system`, niet `url`: deze methode wordt door code aangeroepen
            // met een adres dat de code zelf koos (het logo van een
            // betaalmethode bij Pay. is een svg), niet met een adres dat een
            // beheerder intypt. Het ophalen gaat hoe dan ook door de
            // url-wachter en een svg door de opschoner.
            $incoming = $library->download($path, $folderId, 'system');
            $fileName = $library->storedFileName($incoming);

            // Ook de naam die de vorige versie van deze methode gaf (de
            // kale naam plus het subtype), zodat een eerder geïmporteerd
            // bestand zonder extensie in de url niet nog eens binnenkomt.
            foreach (array_unique([$fileName, $this->legacyExternalName($path, $incoming->localPath)]) as $candidate) {
                if ($existingFile = $this->getFileId($candidate, $folderId)) {
                    @unlink($incoming->localPath);

                    return $existingFile;
                }
            }

            return $library->ingest($incoming, 'create')->item->id;
        } catch (MediaRejectedException $exception) {
            Log::warning('Media geweigerd bij uploadFromPath', [
                'path' => $path,
                'folder' => $folder,
                'reason' => $exception->getMessage(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        if ($incoming && is_file($incoming->localPath)) {
            @unlink($incoming->localPath);
        }

        return null;
    }

    /**
     * Het pad op de schijf `dashed` als deze url naar die schijf wijst.
     */
    private function pathOnOwnDisk(string $url): ?string
    {
        try {
            $base = (string) Storage::disk('dashed')->url('');
        } catch (\Throwable) {
            return null;
        }

        if (strlen($base) < 2 || ! str_starts_with($url, $base)) {
            return null;
        }

        $path = ltrim(rawurldecode((string) preg_split('/[?#]/', substr($url, strlen($base)), 2)[0]), '/');

        // Geen omweg naar boven: dan is het geen pad op de schijf meer.
        if ($path === '' || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $path))) {
            return null;
        }

        return $path;
    }

    private function isPublicOnOwnDisk(string $path): bool
    {
        try {
            return Storage::disk('dashed')->getVisibility($path) === 'public';
        } catch (\Throwable) {
            // Niet vast te stellen is niet publiek.
            return false;
        }
    }

    private function legacyExternalName(string $url, string $localPath): string
    {
        $name = basename($url);

        if (str_contains($name, '.')) {
            return $name;
        }

        $subtype = explode('/', IngestPolicy::sniff($localPath))[1] ?? '';

        return $subtype === '' ? $name : $name . '.' . $subtype;
    }

    /**
     * Van wat in de cache staat naar wat de aanroeper krijgt: een nieuw
     * object per aanroep, met de alt-tekst erop.
     */
    private function present(array|string $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $alt = $payload['alt'] ?? null;
        $captions = $payload['captions'] ?? null;
        $titles = $payload['titles'] ?? null;
        // `trashed` en `pending` zijn alleen voor het leespad zelf, niet voor wie de url krijgt.
        unset($payload['alt'], $payload['captions'], $payload['titles'], $payload['trashed'], $payload['pending']);
        $payload['altText'] = $this->resolveAlt($alt);

        // Alleen als ze gevuld zijn: voor een item zonder bijschrift of eigen
        // titel blijft het resultaat precies wat het was.
        foreach (['caption' => $captions, 'title' => $titles] as $key => $translations) {
            $text = is_array($translations) ? MediaTexts::resolve($translations) : null;

            if ($text !== null) {
                $payload[$key] = $text;
            }
        }

        return (object) $payload;
    }

    // De teksten staan ruw in de cache (een array per taal zodra er meer dan
    // de eerste taal gevuld is) en worden hier pas opgelost voor de taal van
    // dit moment, met terugval op de eerste taal. Zo heeft de cache geen
    // sleutel per taal nodig en lekt er geen taal van het ene verzoek, of de
    // ene wachtrijtaak, naar het volgende.
    private function resolveAlt(mixed $alt): ?string
    {
        if (is_array($alt)) {
            return MediaTexts::resolve(MediaTexts::decode($alt));
        }

        return $alt === null ? null : (string) $alt;
    }

    /**
     * Wat er van de teksten in de cache komt. Een item met alleen de eerste
     * taal draagt zijn alt-tekst als kale waarde, precies zoals voor de
     * vertalingen bestonden.
     *
     * @return array<string, mixed>
     */
    private function textPayload(MediaItem $item): array
    {
        $alt = MediaTexts::of($item, 'alt');
        $payload = ['alt' => array_diff_key($alt, [MediaTexts::firstLocale() => true]) === [] ? $item->alt_text : $alt];

        foreach (['caption', 'title'] as $field) {
            if (($translations = MediaTexts::of($item, $field)) !== []) {
                $payload[$field . 's'] = $translations;
            }
        }

        return $payload;
    }

    private function resolveAndCache(?MediaItem $item, string $conversionName, array|string $conversion, int $mediaId): array|string
    {
        [$payload, $permanent] = $this->resolveMediaUrl($item, $conversionName, $conversion, $mediaId);

        // Een item in de prullenbak: de vlag reist mee in de cache, zodat elke
        // uitlevering gezien wordt zonder dat een gewoon item er iets van merkt.
        if (is_array($payload) && $item && $item->trashed()) {
            $payload['trashed'] = true;
        }

        $key = MediaLibrary::cacheKey($mediaId, $conversionName);

        if ($permanent) {
            Cache::forever($key, $payload);
        } else {
            Cache::put($key, $payload, self::PROVISIONAL_TTL);
        }

        return $payload;
    }

    /**
     * @return array{0: array|string, 1: bool} het resultaat zonder opgeloste
     *                                         alt, en of het voor altijd de cache in mag
     */
    private function resolveMediaUrl(?MediaItem $item, string $conversionName, array|string $conversion, int $mediaId): array
    {
        if (! $item) {
            return ['', false];
        }

        // Een privé-item heeft voor de site geen adres: hetzelfde antwoord
        // als "bestaat niet", en voorlopig in de cache zodat het na de
        // overgang naar publiek vanzelf weer bijtrekt. Voor alles wat hier
        // een adres uit de opgeslagen url's zou halen.
        if (\Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($item)) {
            return ['', false];
        }

        $all = $this->getConversionData($item);

        // conversion_urls bevat alleen definitieve url's; de alt-tekst komt
        // altijd van het item zelf.
        if (isset($all[$conversionName])) {
            return [array_merge($all[$conversionName], $this->textPayload($item)), true];
        }

        $file = $item->file();

        if (! $file) {
            return ['', false];
        }

        // De mediarij is nu geladen: staat het bestand op de privé-schijf, dan is het dicht, wat de vlag ook zegt.
        if (\Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($item)) {
            return ['', false];
        }

        $mime = (string) $file->mime_type;

        $isNonImage = str($mime)->contains([
            'video/',
            'application/pdf',
            'image/svg',
            'image/gif',
        ]);

        // Op de eigen motor ook wat de beelddriver niet kan inlezen (heic,
        // tiff, ico): daar komt nooit een formaat van, dus het origineel is
        // het definitieve antwoord en er wordt niets voor klaargezet.
        if (! $isNonImage && MediaLibrary::usesOwnEngine() && ! MediaConversions::isConvertible($mime)) {
            $isNonImage = true;
        }

        $effective = $isNonImage ? 'original' : $conversionName;
        $permanent = true;

        // Het focuspunt bestaat alleen op de eigen motor. Een formaat dat
        // bijsnijdt staat dan onder een eigen naam op de schijf
        // (`fit-400-400-f3070`); de cache en de kolom `conversions` blijven
        // de kale naam gebruiken.
        $focal = MediaLibrary::usesOwnEngine() ? $item->focalPoint() : null;

        if ($focal && ConversionDefinition::focalSuffix($focal['x'], $focal['y']) === '') {
            $focal = null;
        }

        if ($focal && $effective !== 'original') {
            $effective = ConversionDefinition::effectiveName($conversion, $focal['x'], $focal['y']);
        }

        if ($effective === 'original') {
            $url = $file->getUrl();
        } else {
            $this->rememberRequestedConversion($item, $conversion);

            $url = $this->generatedUrl($file, $effective);

            if ($url === null) {
                if ($this->deferToPump) {
                    // Een kandidaat voor srcset: niet op het pad van de
                    // bezoeker genereren, de pomp maakt hem.
                    $this->wantedFromPump[(int) $item->getKey()] = true;
                } else {
                    $this->queueRegeneration($file, $effective);
                }

                // Met een synchrone wachtrij (lokaal, een kleine site zonder
                // werker) is het formaat er op dit punt al: dan meteen het
                // echte adres, in plaats van een terugval die blijft staan
                // tot de voorlopige cache verloopt.
                $fresh = ! $this->deferToPump && config('queue.default') === 'sync' ? rescue(fn () => $file->fresh(), null, false) : null;
                $url = $fresh ? $this->generatedUrl($fresh, $effective) : null;

                if ($url !== null) {
                    $file = $fresh;
                    $all = $this->getConversionData($item->refresh());
                } else {
                    $permanent = false;
                    // Tussen "focuspunt gezet" en "pomp klaar": eerst de
                    // uitsnede van hetzelfde formaat die er al is (zelfde
                    // verhouding), pas daarna `medium`.
                    $url = (MediaLibrary::usesOwnEngine() ? $this->otherCropUrl($file, $conversionName, $effective) : null)
                        ?? $this->generatedUrl($file, 'medium')
                        ?? $file->getUrl();
                }
            } else {
                // Het bestand is te converteren: een formaat dat er later
                // bij komt hoeft niet op de oplopende pauze te wachten.
                Cache::forget($this->regenerateAttemptsKey($file));
                Cache::forget($this->regenerateLockKey($file));
            }
        }

        $entry = [
            'id' => $mediaId,
            'url' => $url,
            'width' => $file->getCustomProperty('original_width'),
            'height' => $file->getCustomProperty('original_height'),
            'mime' => $mime,
            'path' => $file->getPath(),
            'is_video' => str_starts_with($mime, 'video/'),
        ];

        // Fase 9b: poster, duur en aantal pagina's van een video of pdf,
        // alleen als ze er zijn.
        foreach (rescue(fn () => app(\Dashed\DashedFiles\Classes\Preview\MediaPreviews::class)->deliveryExtras($item, $mime), [], false) as $key => $value) {
            $entry[$key] = $value;
        }

        // Alleen bij een gezet focuspunt en een bestaande AVIF-variant: voor
        // elk ander item blijft het resultaat precies wat het was.
        // Niet bij een formaat dat zelf al rond het focuspunt is uitgesneden:
        // daar zou object-position het beeld een tweede keer verschuiven.
        if ($focal && ! $isNonImage && $effective === $conversionName) {
            $entry['focal'] = $focal;
        }

        // Fase 11: de naamsvermelding van een stockfoto, alleen als die er
        // is. Voor elk ander item blijft het resultaat precies wat het was.
        if (MediaLibrary::usesOwnEngine() && ($credit = rescue(fn () => \Dashed\DashedFiles\Classes\Sources\MediaCredit::for($item), null, false)) !== null) {
            $entry['credit'] = $credit;
        }

        if ($permanent && $effective !== 'original' && MediaLibrary::avifEnabled()
            && ($avif = $this->generatedUrl($file, $effective . ConversionDefinition::AVIF_SUFFIX)) !== null) {
            $entry['avif'] = $avif;
        }

        // Een terugval-url hoort niet in conversion_urls: die kolom wordt
        // als definitief gelezen, en dan zou er na een mislukte taak nooit
        // meer een nieuwe poging volgen. Omgekeerd staat alles wat voor
        // altijd de cache in gaat hier wel, ook een svg of video die onder
        // een formaatnaam is opgevraagd: invalidate() vindt de cachesleutels
        // via deze kolom.
        if ($permanent) {
            $all[$conversionName] = $entry;
            $item->conversion_urls = MediaLibrary::encodeStoredUrls($all);
            // Stil: een bezoeker die een pagina opvraagt hoort geen
            // model-events af te vuren.
            $item->saveQuietly();
        }

        return [array_merge($entry, $this->textPayload($item), $permanent ? [] : ['pending' => true]), $permanent];
    }

    // Tijdens responsive(): een ontbrekend formaat gaat naar de pomp in
    // plaats van naar een losse taak per bestand.
    private bool $deferToPump = false;

    /** @var array<int, true> */
    private array $wantedFromPump = [];

    /**
     * Een afbeelding met een ladder van breedtes, voor `srcset`. Eén
     * aanroep voor alle breedtes: de cache wordt in één keer gelezen en de
     * mediarij hooguit één keer. In de srcset staan alleen breedtes die al
     * gegenereerd zijn; wat ontbreekt wordt bij de pomp aangevraagd en
     * verschijnt bij een volgende weergave.
     *
     * @param  string|array|MediaPreset  $preset  een presetnaam, de arrayvorm (`['widen' => 800]`) of een preset
     * @param  array<int, int>|null  $widths  een eigen ladder in plaats van die van de preset
     * @return object{media: object, src: string, srcset: ?string, sizes: ?string, avifSrcset: ?string, candidates: array<int, string>}|null
     */
    public function responsive(null|int|string|array $mediaId, string|array|MediaPreset $preset, ?array $widths = null): ?object
    {
        if (is_string($preset)) {
            $preset = MediaPresets::get($preset) ?? MediaPreset::fromConversion($preset);
        } elseif (is_array($preset)) {
            $preset = MediaPreset::fromConversion($preset);
        }

        if (! $preset) {
            return null;
        }

        $id = $this->lookupId($mediaId);

        if ($id === null) {
            return null;
        }

        // Ook het basisformaat gaat langs de pomp als het ontbreekt: via
        // deze weg wordt er niets op het pad van de bezoeker gegenereerd.
        $base = $this->payloadsFor($id, [0 => $preset->toConversion()])[0];

        if (! is_array($base) || blank($base['url'] ?? null) || ($base['is_video'] ?? false)) {
            return null;
        }

        $this->noteTrashedServed($id, $base);
        $media = $this->present($base);
        $mime = (string) ($media->mime ?? '');
        $result = ['media' => $media, 'src' => (string) $media->url, 'srcset' => null, 'sizes' => $preset->sizesAttribute(), 'avifSrcset' => null, 'candidates' => []];

        // Een svg, gif of pdf wordt als origineel uitgeleverd: daar is geen ladder van.
        $ladder = MediaConversions::isConvertible($mime) ? $preset->ladder(is_numeric($media->width ?? null) ? (int) $media->width : null, $widths) : [];

        if ($ladder === []) {
            return (object) $result;
        }

        $conversions = [];

        foreach ($ladder as $width) {
            $conversions[$width] = $preset->conversionFor($width);
        }

        $payloads = $this->payloadsFor($id, $conversions);
        $webp = [];
        $avif = [];

        foreach ($ladder as $width) {
            $payload = $payloads[$width] ?? null;

            if (! is_array($payload) || ! empty($payload['pending']) || blank($payload['url'] ?? null)) {
                continue;
            }

            $webp[$width] = $payload['url'];

            if (filled($payload['avif'] ?? null)) {
                $avif[$width] = $payload['avif'];
            }
        }

        $set = fn (array $urls) => implode(', ', array_map(fn (int $width) => $urls[$width] . ' ' . $width . 'w', array_keys($urls)));

        // Eén kandidaat is geen keuze: dan volstaat de gewone src.
        $result['candidates'] = $webp;
        $result['srcset'] = count($webp) > 1 ? $set($webp) : null;
        $result['avifSrcset'] = $result['srcset'] !== null && count($avif) === count($webp) && $preset->wantsAvif() ? $set($avif) : null;

        return (object) $result;
    }

    /** Het id uit wat een aanroeper meegeeft, zoals getSingleMedia() het leest; null voor een geplakt adres of niets. */
    private function lookupId(null|int|string|array $mediaId): ?int
    {
        if (is_array($mediaId)) {
            $mediaId = $mediaId[0] ?? null;
        }

        if (is_string($mediaId) && ! ctype_digit($mediaId)) {
            $mediaId = $this->decryptPickerKey($mediaId);
        }

        return $mediaId && (is_int($mediaId) || ctype_digit((string) $mediaId)) && (int) $mediaId > 0 ? (int) $mediaId : null;
    }

    /**
     * De payloads van één item voor meerdere formaten tegelijk.
     *
     * @param  array<int, array>  $conversions  op breedte
     * @return array<int, array|string>
     */
    private function payloadsFor(int $mediaId, array $conversions): array
    {
        $names = [];
        $keys = [];

        foreach ($conversions as $width => $conversion) {
            $names[$width] = $this->getConversionName($conversion) ?: 'original';

            if (! isset(static::$memoryCache[$mediaId][$names[$width]])) {
                $keys[$width] = MediaLibrary::cacheKey($mediaId, $names[$width]);
            }
        }

        if ($keys !== []) {
            $cached = Cache::many(array_values($keys));
            $missing = [];

            foreach ($keys as $width => $key) {
                if (($cached[$key] ?? null) !== null) {
                    static::$memoryCache[$mediaId][$names[$width]] = $cached[$key];
                } else {
                    $missing[] = $width;
                }
            }

            if ($missing !== []) {
                $item = app(MediaLibrary::class)->find($mediaId, withTrashed: true);

                // Alle nieuwe formaten in één keer in de kolom `conversions`,
                // in plaats van een save per breedte.
                if ($item) {
                    $registered = json_decode($item->conversions ?: '[]', true) ?: [];
                    $before = count($registered);

                    foreach ($missing as $width) {
                        if (! in_array($conversions[$width], $registered, true)) {
                            $registered[] = $conversions[$width];
                        }
                    }

                    if (count($registered) !== $before) {
                        $item->conversions = json_encode($registered);
                        $item->saveQuietly();
                        MediaPickerFileCache::forgetConversions($item->getKey());
                    }
                }

                $this->deferToPump = MediaLibrary::usesOwnEngine();

                try {
                    foreach ($missing as $width) {
                        static::$memoryCache[$mediaId][$names[$width]] = $this->resolveAndCache($item, $names[$width], $conversions[$width], $mediaId);
                    }
                } finally {
                    $this->deferToPump = false;
                }

                if (isset($this->wantedFromPump[$mediaId])) {
                    unset($this->wantedFromPump[$mediaId]);
                    app(Conversions\ConversionPump::class)->requestMissing($mediaId);
                }
            }
        }

        $payloads = [];

        foreach ($names as $width => $name) {
            $payloads[$width] = static::$memoryCache[$mediaId][$name];
        }

        return $payloads;
    }

    private function getConversionData(MediaItem $item): array
    {
        return MediaLibrary::storedUrls($item->conversion_urls);
    }

    private function rememberRequestedConversion(MediaItem $item, array|string $conversion): void
    {
        $registered = json_decode($item->conversions ?: '[]', true) ?: [];

        if (in_array($conversion, $registered, true)) {
            return;
        }

        $registered[] = $conversion;
        $item->conversions = json_encode($registered);
        // Spatie registreert formaten op een leeg model en leest ze dus uit
        // de database; zonder opslaan kent hij dit formaat niet.
        $item->saveQuietly();
        MediaPickerFileCache::forgetConversions($item->getKey());
    }

    /**
     * De url van een formaat, of null als het niet gegenereerd is of wel als
     * gegenereerd op de media staat maar niet (meer) geregistreerd is;
     * Spatie gooit in dat laatste geval een InvalidConversion.
     */
    private function generatedUrl(Media $file, string $conversionName): ?string
    {
        if (! $file->hasGeneratedConversion($conversionName)) {
            return null;
        }

        try {
            return $file->getUrl($conversionName);
        } catch (InvalidConversion) {
            return null;
        }
    }

    /**
     * Het adres van dezelfde uitsnede met een ander (of zonder) focuspunt,
     * als die er nog staat: de terugval zolang de nieuwe niet gemaakt is.
     * Die naam is niet meer geregistreerd, dus het adres wordt afgeleid van
     * dat van `medium`, dat altijd geregistreerd is en in dezelfde map staat.
     */
    private function otherCropUrl(Media $file, string $bareName, string $effective): ?string
    {
        $generated = array_keys(array_filter((array) $file->generated_conversions));
        $stale = (array) $file->getCustomProperty('stale_conversions', []);
        $candidates = array_values(array_filter($generated, fn ($name) => is_string($name)
            && $name !== $effective
            && ! str_ends_with($name, ConversionDefinition::AVIF_SUFFIX)
            && ConversionDefinition::withoutFocalSuffix($name) === $bareName));

        if ($candidates === []) {
            return null;
        }

        // De laatst afgedankte eerst: dat is het vorige focuspunt.
        usort($candidates, fn (string $a, string $b) => strcmp((string) ($stale[$b] ?? ''), (string) ($stale[$a] ?? '')));

        try {
            $template = $file->getUrl('medium');
        } catch (\Throwable) {
            return null;
        }

        $url = preg_replace('/-medium\.[a-z0-9]+(?=$|\?)/i', '-' . $candidates[0] . '.' . ConversionDefinition::FORMAT, $template, 1, $count);

        return $count === 1 && is_string($url) ? $url : null;
    }

    private function queueRegeneration(Media $file, string $conversionName): void
    {
        // Op de eigen motor: loopt er al een run die dit item meeneemt, dan
        // maakt die het formaat. Een losse taak ernaast is dubbel werk.
        if (MediaLibrary::usesOwnEngine() && app(Conversions\ConversionPump::class)->covers((int) $file->model_id)) {
            return;
        }

        // Slot en teller staan op het bestand en niet op het formaat: de taak
        // maakt alle ontbrekende formaten van het bestand in één keer, dus
        // zes ontbrekende formaten zijn één taak en geen zes.
        $attemptsKey = $this->regenerateAttemptsKey($file);
        $attempts = (int) Cache::get($attemptsKey, 0);
        $wait = (int) min(self::REGENERATE_WAIT * (2 ** min($attempts, 16)), self::REGENERATE_MAX_WAIT);

        // add() en niet has()+put(): twee verzoeken tegelijk zetten zo samen
        // maar één taak klaar.
        if (! Cache::add($this->regenerateLockKey($file), true, $wait)) {
            return;
        }

        Cache::put($attemptsKey, $attempts + 1, self::REGENERATE_MAX_WAIT * 7);

        RegenerateMediaLibraryConversions::dispatch(
            $file->id,
            null,
            $conversionName,
            $file->generated_conversions ?? []
        );
    }

    private function regenerateLockKey(Media $file): string
    {
        return "regenerate-media-{$file->id}";
    }

    private function regenerateAttemptsKey(Media $file): string
    {
        return "regenerate-media-attempts-{$file->id}";
    }
}
