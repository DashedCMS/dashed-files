<?php

namespace Dashed\DashedFiles\Classes;

use Closure;
use Throwable;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Dashed\DashedFiles\Models\MediaFolder;
use Dashed\DashedFiles\Jobs\HashMediaItems;
use Dashed\DashedFiles\Events\MediaIngested;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Auth\Authenticatable;
use Dashed\DashedFiles\Observers\MediaObserver;
use Dashed\DashedFiles\Classes\Svg\SvgSanitizer;
use Dashed\DashedFiles\Classes\Browse\MediaMirror;
use Dashed\DashedFiles\Classes\Browse\MediaTagging;
use Dashed\DashedFiles\Classes\Ingest\IngestPolicy;
use Dashed\DashedFiles\Classes\Ingest\IngestResult;
use Dashed\DashedFiles\Classes\Ingest\IncomingMedia;
use Dashed\DashedFiles\Classes\Ingest\MediaChecksums;
use Dashed\DashedFiles\Classes\Ingest\PreparedMedia;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;

class MediaLibrary
{
    private const TEXT_FIELDS = ['alt_text', 'alt', 'caption', 'title'];

    // Ophogen maakt elk gecachet resultaat in één keer ongeldig. v2: de
    // sleutels daarvoor waren eeuwig en droegen een bevroren alt-tekst.
    private const CACHE_VERSION = 'v2';

    // Deze namen kan een thema altijd opvragen, ook als ze niet in de kolom
    // `conversions` staan.
    private const ALWAYS_CACHED = ['original', 'medium', 'thumb'];

    // De generatieteller staat zelf in de cache en zit in elke sleutel:
    // ophogen maakt alles in één keer onvindbaar, ook namen die nergens
    // geregistreerd staan.
    private const GENERATION_KEY = 'media-library-media-generation';

    // conversion_urls is een omhulsel {"v":2,"urls":{...}}. Alles wat
    // daarbuiten staat komt van oudere code, kan een bevroren terugval-url
    // zijn en wordt niet als definitief gelezen. Zo doet het er niet toe of
    // de reset-migratie voor of na de nieuwe code draait, en evenmin of een
    // werker met oude code er nog iets bij schrijft.
    private const URLS_VERSION = 2;

    private static ?int $generation = null;

    public const ENGINE_SETTING = 'media_library_engine';

    public const ENGINE_LEGACY = 'legacy';

    public const ENGINE_OWN = 'dashed';

    public const ENGINES = [self::ENGINE_LEGACY, self::ENGINE_OWN];

    private const ENGINE_MEMO = 'dashed-files.media-engine';

    /**
     * De motor zonder instelling en zonder env. Sinds release A de eigen
     * bibliotheek; het oude pakket blijft geïnstalleerd als terugval voor een
     * site die `legacy` expliciet instelt (instelling of env).
     */
    public const ENGINE_DEFAULT = self::ENGINE_OWN;

    /**
     * Welke beheerpagina en kiezer er draaien: die van het pakket (legacy) of
     * de eigen (dashed). Eén stand voor de hele installatie, op de eerste
     * site, met `dashed-files.media_engine` (env DASHED_MEDIA_ENGINE) erboven
     * voor een pilot. Zonder geldige instelling: ENGINE_DEFAULT. Onthouden op
     * het verzoek en niet in een static: die overleeft onder Octane het
     * verzoek en in tests de herstart.
     */
    public static function engine(): string
    {
        $request = app()->bound('request') ? request() : null;

        if ($request && is_string($memo = $request->attributes->get(self::ENGINE_MEMO))) {
            return $memo;
        }

        $engine = config('dashed-files.media_engine');

        if (! in_array($engine, self::ENGINES, true)) {
            try {
                $engine = Customsetting::get(self::ENGINE_SETTING, (string) Sites::getFirstSite()['id'], self::ENGINE_DEFAULT);
            } catch (Throwable) {
                // Midden in een uitrol kan de instellingentabel ontbreken.
                $engine = self::ENGINE_DEFAULT;
            }
        }

        $engine = in_array($engine, self::ENGINES, true) ? $engine : self::ENGINE_DEFAULT;

        $request?->attributes->set(self::ENGINE_MEMO, $engine);

        return $engine;
    }

    /**
     * Waar de stand vandaan komt: `env` (DASHED_MEDIA_ENGINE), `setting` (de
     * opgeslagen instelling) of `default` (niets ingesteld). Voor de doctor en
     * dashed:media-engine; niet per verzoek nodig, dus niet onthouden.
     */
    public static function engineSource(): string
    {
        if (in_array(config('dashed-files.media_engine'), self::ENGINES, true)) {
            return 'env';
        }

        try {
            $stored = Customsetting::get(self::ENGINE_SETTING, (string) Sites::getFirstSite()['id']);
        } catch (Throwable) {
            return 'default';
        }

        return in_array($stored, self::ENGINES, true) ? 'setting' : 'default';
    }

    public static function usesOwnEngine(): bool
    {
        return self::engine() === self::ENGINE_OWN;
    }

    public static function setEngine(string $engine): void
    {
        if (! in_array($engine, self::ENGINES, true)) {
            throw new InvalidArgumentException("Unknown media engine [{$engine}].");
        }

        // Het oude pakket kent de prullenbak niet en zou alles wat erin ligt
        // als gewoon item tonen, zonder weg terug. Daarom eerst alles terugzetten.
        if ($engine === self::ENGINE_LEGACY) {
            $restored = rescue(fn () => app(\Dashed\DashedFiles\Classes\Browse\MediaBulk::class)->restoreEverything(), ['items' => 0, 'folders' => 0]);

            if ($restored['items'] + $restored['folders'] > 0) {
                Log::warning('Media engine set to [legacy]: restored ' . $restored['items'] . ' item(s) and ' . $restored['folders'] . ' folder(s) from the trash, the legacy package has no trash.');
            }
        }

        Customsetting::set(self::ENGINE_SETTING, $engine, (string) Sites::getFirstSite()['id']);

        self::forgetEngine();

        // De slug van de pagina en de lijst van plugins liggen bij het
        // opstarten vast, en de routes dus in de routecache.
        if (app()->routesAreCached()) {
            Log::warning('Media engine set to [' . $engine . '] while routes are cached: run route:clear (or dashed:media-engine) and restart workers.');
        }
    }

    public static function forgetEngine(): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove(self::ENGINE_MEMO);
        }
    }

    /** De sleutel die "dit weggegooide item is vandaag al als uitgeleverd genoteerd" onthoudt. */
    public static function servedKey(int|string $id): string
    {
        return 'media-library-trashed-served-' . $id;
    }

    public static function cacheKey(int|string $id, string $name): string
    {
        return 'media-library-media-' . self::CACHE_VERSION . '-' . self::generation() . "-{$id}-{$name}";
    }

    /**
     * De definitieve url's uit de kolom conversion_urls, per formaatnaam.
     *
     * @return array<string, array>
     */
    public static function storedUrls(?string $json): array
    {
        $data = json_decode($json ?: '[]', true);

        if (! is_array($data) || ($data['v'] ?? null) !== self::URLS_VERSION || ! is_array($data['urls'] ?? null)) {
            return [];
        }

        return array_filter($data['urls'], 'is_array');
    }

    public static function encodeStoredUrls(array $urls): string
    {
        return json_encode(['v' => self::URLS_VERSION, 'urls' => $urls]);
    }

    // In milliseconden: een teller die wegvalt nadat hij binnen dezelfde
    // seconde een paar keer is opgehoogd, begint zo toch boven zijn oude waarde.
    private static function clock(): int
    {
        return (int) now()->getTimestampMs();
    }

    private static function generation(): int
    {
        if (self::$generation !== null) {
            return self::$generation;
        }

        $generation = Cache::get(self::GENERATION_KEY);

        if (! is_int($generation)) {
            // De teller is uit de cache gevallen. Op de klok beginnen: dat
            // is hoger dan elke eerdere waarde, dus sleutels van voor het
            // wegvallen komen niet terug. add() zodat twee processen die
            // tegelijk beginnen op dezelfde waarde uitkomen.
            Cache::add(self::GENERATION_KEY, self::clock());
            $generation = (int) Cache::get(self::GENERATION_KEY, self::clock());
        }

        return self::$generation = $generation;
    }

    /**
     * @param  bool  $withTrashed  ook een item in de prullenbak. Het leespad van de site vraagt dit altijd: een weggegooid bestand blijft zichtbaar tot het definitief weg is, want de gebruiksindex kan een veld missen en de prullenbak is het vangnet.
     */
    public function find(int|string|null $id, bool $includeWithoutFile = false, bool $withTrashed = false): ?MediaItem
    {
        if (! $this->isId($id)) {
            return null;
        }

        $query = $includeWithoutFile
            ? MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)
            : MediaItem::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->find((int) $id);
    }

    /**
     * @return Collection<int, MediaItem> op id gesleuteld
     */
    public function findMany(array $ids, bool $withTrashed = false): Collection
    {
        $ids = $this->ids($ids);

        if ($ids === []) {
            return new Collection();
        }

        return MediaItem::query()->when($withTrashed, fn (Builder $query) => $query->withTrashed())->whereKey($ids)->get()->keyBy('id');
    }

    public function itemForMedia(Media $media): ?MediaItem
    {
        return MediaItem::fromMedia($media);
    }

    public function folder(int|string|null $id): ?MediaFolder
    {
        return $this->isId($id) ? MediaFolder::find((int) $id) : null;
    }

    public function folderIdForPath(string $path): int
    {
        $names = array_values(array_filter(array_map('trim', explode('/', $path)), fn (string $name) => $name !== ''));

        if ($names === []) {
            throw new InvalidArgumentException('A folder path needs at least one folder name.');
        }

        $parentId = null;

        foreach ($names as $name) {
            $parentId = MediaFolder::firstOrCreate(['name' => $name, 'parent_id' => $parentId])->getKey();
        }

        return (int) $parentId;
    }

    public function findInFolderByFileName(string $fileName, ?int $folderId): ?int
    {
        $id = MediaItem::query()
            ->where('folder_id', $folderId)
            ->whereHas('media', fn (Builder $query) => $query
                ->where('collection_name', MediaItem::COLLECTION)
                ->where('file_name', $fileName))
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    public function createFromDisk(string $path, ?int $folderId, string $disk = 'dashed', bool $preserveOriginal = true, ?int $uploadedByUserId = null): ?MediaItem
    {
        $item = MediaItem::create([
            'folder_id' => $folderId,
            'uploaded_by_user_id' => $uploadedByUserId,
        ]);

        try {
            $adder = $item->addMediaFromDisk($path, $disk);

            if ($preserveOriginal) {
                $adder->preservingOriginal();
            }

            $adder->toMediaCollection(MediaItem::COLLECTION);
        } catch (Throwable $exception) {
            // Zonder bestand is het item onzichtbaar (de scope) en zou het
            // als lege rij in de tabel achterblijven.
            $item->forceDelete();
            report($exception);

            return null;
        }

        return $item->unsetRelation('media');
    }

    /**
     * De ene ingang voor elk nieuw bestand, ongeacht waar het vandaan komt.
     * Alles wat hier beslist wordt gaat op de inhoud van het bestand; de
     * naam en het type dat de afzender opgeeft zijn alleen een hint.
     *
     * @param  string  $onDuplicate  'reuse' geeft bij dezelfde bytes het bestaande item terug, 'create' maakt toch een nieuw item
     *
     * @throws MediaRejectedException
     */
    public function ingest(IncomingMedia $file, string $onDuplicate = 'reuse'): IngestResult
    {
        $prepared = null;

        try {
            $prepared = $this->prepare($file);
            $path = $prepared->path;
            $mime = $prepared->mime;
            $checksum = $prepared->checksum;
            $size = $prepared->size;
            $properties = $prepared->properties;
            $checksums = app(MediaChecksums::class);
            $tracked = $checksums->available();

            // Alleen als de uitkomst gebruikt wordt: de controle kan oudere
            // bestanden van de schijf moeten lezen.
            if ($tracked && $onDuplicate === 'reuse') {
                if ($checksums->hashUnhashedOfSize($size)) {
                    $this->queueHashing();
                }

                // Alleen binnen dezelfde zichtbaarheid: een upload in een
                // privé-map mag niet uitkomen bij een publiek item (het
                // bestand zou dan open blijven staan), en omgekeerd niet bij
                // een privé-item dat de site nooit kan tonen.
                $private = app(\Dashed\DashedFiles\Classes\Access\MediaVisibility::class)->isFolderPrivate($file->folderId);

                if ($existing = $this->findByChecksum($checksum, $size, private: $private)) {
                    return new IngestResult($existing, true, $checksum);
                }

                // Hetzelfde bestand ligt in de prullenbak: niet stil
                // hergebruiken (het item is in het beheer onzichtbaar) en
                // geen tweede maken, maar terugzetten en dat zeggen.
                if ($trashed = $this->findByChecksum($checksum, $size, onlyTrashed: true, private: $private)) {
                    app(\Dashed\DashedFiles\Classes\Browse\MediaBulk::class)->restoreItems([(int) $trashed->getKey()]);

                    return new IngestResult(MediaItem::query()->find($trashed->getKey()) ?? $trashed, true, $checksum, true);
                }
            }

            $item = MediaItem::create(array_merge(
                ['folder_id' => $file->folderId, 'uploaded_by_user_id' => $file->uploadedByUserId],
                $tracked ? ['checksum' => $checksum, 'size' => $size] : [],
            ));

            try {
                $media = $this->attach($item, $prepared, $file, MediaItem::collectionName());

                // Spatie snuift zelf ook, maar kent de correcties niet (heic
                // als octet-stream, een svg als kale xml).
                if ($media->mime_type !== $mime) {
                    $media->mime_type = $mime;
                    $media->saveQuietly();
                    // Stil opgeslagen, dus de observer die de spiegelkolommen bijhoudt ziet dit niet.
                    $this->syncMirror($item);
                }
            } catch (Throwable $exception) {
                // Zonder bestand is het item onzichtbaar (de scope) en zou
                // het als lege rij in de tabel achterblijven. Definitief: een
                // mislukte upload hoort niet in de prullenbak.
                $item->forceDelete();
                report($exception);

                throw new MediaRejectedException(__('Het bestand kon niet worden opgeslagen.'), 0, $exception);
            }

            $item->unsetRelation('media');
            \Dashed\DashedFiles\Classes\Browse\MediaFolderTree::forgetDuplicatesMemo();

            if (IngestPolicy::isRaster($mime)) {
                event(new MediaIngested($item, $file->source));
            }

            // Een video of pdf: duur, pagina's en een voorvertoning komen uit
            // de wachtrij, nooit uit dit verzoek. Niet voor een duplicaat
            // (dat is hierboven al teruggegeven). Of het bestand naar een
            // extern programma mag (nooit uit de chat of de API) beslist
            // MediaPreviews zelf, bij het kiezen van de generator.
            if (\Dashed\DashedFiles\Classes\Preview\MediaPreviews::kindOf($mime) !== null) {
                app(\Dashed\DashedFiles\Classes\Preview\MediaPreviews::class)->queue((int) $item->getKey());
            }

            // Een gif: de statische poster voor het beheer, ook uit de wachtrij.
            if (\Dashed\DashedFiles\Classes\Preview\GifPoster::isGif($mime)) {
                \Dashed\DashedFiles\Classes\Preview\GifPoster::queue((int) $item->getKey());
            }

            return new IngestResult($item, false, $checksum);
        } finally {
            $this->cleanUp($file, $prepared);
        }
    }

    /**
     * Elke controle die een nieuw bestand krijgt, zonder iets te bewaren:
     * snuiven, de allowlist van de bron, de wachter, de svg-opschoner, de
     * megapixelgrens en de checksum. Een upload en het vervangen van een
     * bestaand bestand gaan hier allebei doorheen.
     *
     * @throws MediaRejectedException
     */
    public function prepare(IncomingMedia $file): PreparedMedia
    {
        // Een opgeschoonde svg komt in een eigen bestand als dat van de
        // aanroeper moet blijven staan.
        $ownCopy = null;

        try {
            $path = $file->localPath;

            if (! is_file($path) || ! is_readable($path)) {
                throw new MediaRejectedException(__('Het bestand is niet gevonden of niet leesbaar.'));
            }

            $mime = IngestPolicy::sniff($path, $file->fileName);

            if ($mime === '' || IngestPolicy::isNeverAllowed($mime) || ! in_array($mime, IngestPolicy::allowedFor($file->source, $file->allowedMimes), true)) {
                throw new MediaRejectedException(__('Het bestandstype :mime is hier niet toegestaan.', ['mime' => $mime ?: __('onbekend')]));
            }

            $fileName = IngestPolicy::fileName($file->fileName, $mime);

            if ($fileName === null) {
                throw new MediaRejectedException(__('Voor het bestandstype :mime is geen extensie bekend.', ['mime' => $mime]));
            }

            // De wachter oordeelt over de naam waaronder bewaard wordt, niet
            // over die van de bron: `GetImage.ashx?id=5` met een jpeg erin
            // wordt `GetImage.jpg`, en onzichtbare tekens zijn er dan al uit.
            if ($reason = $this->guardReason($path, $fileName, $mime)) {
                throw new MediaRejectedException($reason);
            }

            $properties = [];

            if ($mime === IngestPolicy::SVG) {
                $sanitizer = app(SvgSanitizer::class);
                $clean = $sanitizer->sanitize((string) file_get_contents($path), 'file');

                if ($clean === null) {
                    throw new MediaRejectedException(__('Deze svg bevat onderdelen die niet zijn toegestaan en kon niet veilig worden opgeschoond.'));
                }

                if (! $file->deleteLocalAfter) {
                    $path = $ownCopy = IncomingMedia::temporaryPath();
                }

                if (file_put_contents($path, $clean) === false) {
                    throw new MediaRejectedException(__('Het tijdelijke bestand kon niet worden aangemaakt.'));
                }

                clearstatcache(true, $path);
                $properties['svg_sanitized'] = SvgSanitizer::VERSION;
                $dimensions = $sanitizer->dimensions($clean);
            } elseif (IngestPolicy::isRaster($mime)) {
                $dimensions = IngestPolicy::dimensions($path, $mime);
                $this->guardPixelCount($dimensions, $file->source);
            } else {
                $dimensions = null;
            }

            // Hier gelezen en meegegeven: de observer op de mediarij draait
            // voordat Spatie het bestand naar de schijf heeft gezet.
            if ($dimensions) {
                $properties['original_width'] = $dimensions['width'];
                $properties['original_height'] = $dimensions['height'];
            }

            $properties['source'] = $file->source;
            $properties['source_meta'] = $file->meta;

            return new PreparedMedia($path, $mime, $fileName, $properties, $dimensions, (string) hash_file('sha256', $path), (int) filesize($path), $ownCopy);
        } catch (Throwable $exception) {
            // Na een weigering is er geen PreparedMedia om de kopie aan op te hangen.
            if ($ownCopy !== null && is_file($ownCopy)) {
                @unlink($ownCopy);
            }

            throw $exception;
        }
    }

    /**
     * Zet een voorbereid bestand als mediarij op het item.
     */
    private function attach(MediaItem $item, PreparedMedia $prepared, IncomingMedia $file, string $collection, array $extraProperties = []): Media
    {
        // De schijf levert uit onder het type dat hij bij het opslaan
        // meekrijgt, en Spatie zou daar zijn eigen, ongecorrigeerde
        // gok voor nemen (een heic als octet-stream).
        $adder = $item->addMedia($prepared->path)
            ->usingFileName($prepared->fileName)
            ->withCustomProperties(array_merge($prepared->properties, $extraProperties))
            ->addCustomHeaders(['ContentType' => $prepared->mime]);

        if (! $file->deleteLocalAfter && $prepared->ownCopy === null) {
            $adder->preservingOriginal();
        }

        // Een item in een privé-map bewaart alles op de privé-schijf: het
        // bestand zelf, een vervanging en wat er tijdens het wisselen even staat.
        return $adder->toMediaCollection($collection, \Dashed\DashedFiles\Classes\Access\MediaVisibility::diskFor($item));
    }

    private function cleanUp(IncomingMedia $file, ?PreparedMedia $prepared): void
    {
        foreach (array_filter([$prepared?->ownCopy, $file->deleteLocalAfter ? $file->localPath : null]) as $leftover) {
            if (is_file($leftover)) {
                @unlink($leftover);
            }
        }
    }

    /**
     * Vervangt het bestand van een item. Het id blijft, de url verandert
     * (een nieuwe mediarij in een nieuwe map): browser en CDN zouden het
     * oude beeld anders tot dertig dagen blijven tonen. Het vorige bestand
     * blijft in de collectie `library-replaced` staan tot de bewaartermijn
     * `media_replaced`, zodat verstuurde mails en gedeelde links blijven
     * werken.
     *
     * @throws MediaRejectedException
     */
    public function replace(MediaItem $item, IncomingMedia $file): MediaItem
    {
        return $this->swap($item, $file);
    }

    /**
     * De wissel achter replace() en applyEdits(). $file is het nieuwe
     * bestand, of een closure die het pas maakt als het slot er is (een
     * bewerking leest dan de bron van dat moment). $parkIn is de collectie
     * waar het oude bestand heen gaat: `library-replaced` (verloopt na de
     * bewaartermijn) of `library-source` (de onaangetaste bron van een
     * bewerking). $edits is het recept dat op het item komt te staan; zonder
     * recept is het een gewone vervanging en hoort een bewaarde bron er
     * niet meer bij.
     *
     * @param  IncomingMedia|Closure(MediaItem, Media): array{0: IncomingMedia, 1: string}  $file
     *
     * @throws MediaRejectedException
     */
    private function swap(MediaItem $item, IncomingMedia|Closure $file, ?array $edits = null): MediaItem
    {
        $id = (int) $item->getKey();
        $lock = Cache::lock("media-replace-{$id}", 120);
        $prepared = null;
        $parkIn = MediaItem::COLLECTION_REPLACED;
        $make = $file instanceof Closure ? $file : null;
        $file = $file instanceof Closure ? null : $file;

        if (! $lock->get()) {
            // Het bestand van de aanroeper ruimen we wel op: hij komt hier niet meer terug.
            if ($file) {
                $this->cleanUp($file, null);
            }

            throw new MediaRejectedException(__('Dit bestand wordt op dit moment al vervangen. Probeer het zo opnieuw.'));
        }

        try {
            // Vers gelezen: het object van de aanroeper kan ouder zijn dan een vorige vervanging.
            $item = MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->withTrashed()->find($id)
                ?? throw new MediaRejectedException(__('Dit bestand bestaat niet meer.'));
            $old = $item->file();

            if (! $old) {
                throw new MediaRejectedException(__('Dit item heeft geen bestand om te vervangen.'));
            }

            if ($make) {
                [$file, $parkIn] = $make($item, $old);
            }

            $prepared = $this->prepare($file);

            $kind = fn (?string $mime) => match (\Dashed\DashedFiles\Classes\Browse\MediaItemPayload::typeOf($mime)) {
                'svg', 'image' => 'image',
                'video' => 'video',
                default => 'document',
            };

            if ($kind($prepared->mime) !== $kind($old->mime_type)) {
                $names = ['image' => __('een afbeelding'), 'video' => __('een video'), 'document' => __('een document')];

                throw new MediaRejectedException(__('Dit bestand is :oud en kan alleen door :oud worden vervangen, niet door :nieuw.', [
                    'oud' => $names[$kind($old->mime_type)],
                    'nieuw' => $names[$kind($prepared->mime)],
                ]));
            }

            // Eerst in een eigen collectie: tot de wissel hieronder is er voor
            // het item niets veranderd, ook niet als het bewaren mislukt.
            try {
                $new = $this->attach($item, $prepared, $file, MediaItem::COLLECTION_INCOMING, ['replacement_of' => (int) $old->getKey()]);
            } catch (Throwable $exception) {
                report($exception);

                throw new MediaRejectedException(__('Het bestand kon niet worden opgeslagen.'), 0, $exception);
            }

            $mirror = BrowseSchema::hasMirror()
                ? MediaMirror::attributesFor((object) ['file_name' => $new->file_name, 'mime_type' => $prepared->mime, 'size' => $prepared->size, 'custom_properties' => $new->custom_properties])
                : [];

            try {
                DB::transaction(function () use ($item, $old, $new, $prepared, $mirror, $parkIn, $edits) {
                    // Het slot in de cache verloopt na twee minuten. Daarom
                    // hier, met de rijen vast, nog één keer: is dit nog het
                    // bestand dat we vervangen, en bestaat het item nog.
                    $current = Media::query()->whereKey($old->getKey())->lockForUpdate()->first(['id', 'collection_name']);
                    $exists = MediaItem::withoutGlobalScopes()->toBase()->where('id', $item->getKey())->lockForUpdate()->exists();

                    if (! $exists || ! $current || $current->collection_name !== MediaItem::COLLECTION) {
                        throw new MediaRejectedException(__('Dit bestand is intussen door iemand anders vervangen of verwijderd. Laad de pagina opnieuw en probeer het nog eens.'));
                    }

                    // Stil: de observer zou de spiegelkolommen anders met de oude rij overschrijven.
                    $old->collection_name = $parkIn;

                    if ($parkIn === MediaItem::COLLECTION_SOURCE) {
                        // De bron van een bewerking: bij herstellen komen de
                        // checksum en de grootte van het item hiervandaan.
                        $old->setCustomProperty('source_checksum', $item->getAttribute('checksum'));
                        $old->setCustomProperty('source_since', now()->toIso8601String());
                    } else {
                        $old->setCustomProperty('replaced_at', now()->toIso8601String());
                    }

                    $old->saveQuietly();

                    // Een gewone vervanging: de bewaarde bron van een eerdere
                    // bewerking hoort niet meer bij dit bestand en verloopt mee.
                    if ($edits === null) {
                        foreach (Media::query()->where('model_id', $item->getKey())->whereIn('model_type', [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS])->where('collection_name', MediaItem::COLLECTION_SOURCE)->get() as $source) {
                            $source->collection_name = MediaItem::COLLECTION_REPLACED;
                            $source->setCustomProperty('replaced_at', now()->toIso8601String());
                            $source->saveQuietly();
                        }
                    }

                    $new->collection_name = MediaItem::COLLECTION;
                    $new->mime_type = $prepared->mime;
                    $new->saveQuietly();

                    // De kolom `conversions` (welke formaten dit item gebruikt) blijft staan.
                    MediaItem::withoutGlobalScopes()->toBase()->where('id', $item->getKey())->update(array_merge(
                        $mirror,
                        app(MediaChecksums::class)->available() ? ['checksum' => $prepared->checksum, 'size' => $prepared->size] : [],
                        ['conversion_urls' => null, 'updated_at' => now()],
                        // Een ander beeld: het focuspunt van het oude zegt er niets meer over.
                        BrowseSchema::hasFocal() ? ['focal_x' => null, 'focal_y' => null] : [],
                        BrowseSchema::hasEdits() ? ['edits' => $edits === null ? null : json_encode($edits)] : [],
                        // Duur en pagina's waren van het oude bestand. Een zelf gekozen poster blijft.
                        BrowseSchema::hasPreview() ? ['duration' => null, 'pages' => null, 'preview_checked_at' => null] : [],
                    ));
                });
            } catch (Throwable $exception) {
                // De nieuwe rij en zijn bestand weer weg: er is niets veranderd.
                rescue(fn () => $new->delete());

                if ($exception instanceof MediaRejectedException) {
                    throw $exception;
                }

                report($exception);

                throw new MediaRejectedException(__('Het bestand kon niet worden vervangen.'), 0, $exception);
            }

            // De automatische voorvertoning hoorde bij het oude bestand: weg,
            // en voor een video of pdf opnieuw aanvragen.
            $previews = app(\Dashed\DashedFiles\Classes\Preview\MediaPreviews::class);
            $previews->dropPreview($id);

            if (\Dashed\DashedFiles\Classes\Preview\MediaPreviews::kindOf($prepared->mime) !== null) {
                $previews->queue($id);
            }

            if (\Dashed\DashedFiles\Classes\Preview\GifPoster::isGif($prepared->mime)) {
                \Dashed\DashedFiles\Classes\Preview\GifPoster::queue($id);
            }

            // Wie dit item als poster gebruikt toont nu een oud adres.
            $previews->invalidateUsersOfPoster($id);

            // Het oude object draagt nog de oude conversion_urls: daar komen
            // de namen vandaan van wat er gecachet staat.
            $this->invalidate($item, 'replaced');
            MediaPickerFileCache::flush();

            $fresh = MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->withTrashed()->find($id) ?? $item;

            \Dashed\DashedFiles\Jobs\FlushMediaDependentCaches::queueFor($id, rewriteSources: true);

            event(new \Dashed\DashedFiles\Events\MediaReplaced($fresh, (int) $old->getKey(), (int) $new->getKey()));

            return $fresh;
        } finally {
            if ($file) {
                $this->cleanUp($file, $prepared);
            }

            rescue(fn () => $lock->release(), report: false);
        }
    }

    /**
     * Kiest een afbeelding uit de bibliotheek als poster van een video (of
     * als miniatuur van een pdf). Null haalt de eigen keuze weg: het leespad
     * valt dan terug op de automatische voorvertoning.
     *
     * @throws MediaRejectedException
     */
    public function setPoster(MediaItem $item, ?int $posterItemId): MediaItem
    {
        self::requireOwnEngine();

        if (! BrowseSchema::hasPreview()) {
            throw new MediaRejectedException(__('Een eigen poster kan pas na het bijwerken van de database (migraties).'));
        }

        if (\Dashed\DashedFiles\Classes\Preview\MediaPreviews::kindOf($item->file()?->mime_type) === null) {
            throw new MediaRejectedException(__('Alleen een video of pdf heeft een poster.'));
        }

        if ($posterItemId !== null) {
            $poster = $posterItemId > 0 && $posterItemId !== (int) $item->getKey() ? $this->find($posterItemId) : null;

            if (! $poster || \Dashed\DashedFiles\Classes\Browse\MediaItemPayload::typeOf($poster->file()?->mime_type) !== 'image') {
                throw new MediaRejectedException(__('Kies een afbeelding uit de bibliotheek als poster.'));
            }
        }

        MediaItem::withoutGlobalScopes()->toBase()->where('id', $item->getKey())->update(['poster_item_id' => $posterItemId, 'updated_at' => now()]);

        $this->invalidate($item, 'poster');
        MediaPickerFileCache::flush();

        return MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->withTrashed()->find($item->getKey()) ?? $item;
    }

    /**
     * Vraagt de voorvertoning van een video of pdf opnieuw aan. Alleen als
     * de server die zelf kan maken; het werk gebeurt in de wachtrij.
     *
     * @throws MediaRejectedException
     */
    public function regeneratePreview(MediaItem $item): void
    {
        self::requireOwnEngine();

        $previews = app(\Dashed\DashedFiles\Classes\Preview\MediaPreviews::class);

        // Per bestand: een bestand uit de chat of de API gaat nooit naar een extern programma.
        if (! \Dashed\DashedFiles\Classes\Preview\MediaPreviews::available() || $previews->generatorForFile($item, $item->file()) === null) {
            throw new MediaRejectedException(__('Deze server kan van dit bestand geen voorvertoning maken.'));
        }

        // Uitdrukkelijk gevraagd: ook zonder werker het hele werk, na het antwoord.
        $previews->queue((int) $item->getKey(), explicit: true);
    }

    /**
     * Zet het focuspunt van een afbeelding, in procenten van links en van
     * boven. Null (of 50/50) is het midden: het gedrag van altijd. De
     * formaten die bijsnijden krijgen een andere naam en dus een ander
     * adres; ze worden via de pomp gemaakt, niet in dit verzoek.
     *
     * @throws MediaRejectedException
     */
    public function setFocalPoint(MediaItem $item, ?int $x, ?int $y): MediaItem
    {
        self::requireOwnEngine();

        if (! BrowseSchema::hasFocal()) {
            throw new MediaRejectedException(__('De kolommen voor het focuspunt ontbreken nog. Draai eerst de migraties.'));
        }

        if (($x === null) !== ($y === null)) {
            throw new MediaRejectedException(__('Een focuspunt heeft twee waarden nodig.'));
        }

        if ($x !== null && ($x < 0 || $x > 100 || $y < 0 || $y > 100)) {
            throw new MediaRejectedException(__('Het focuspunt ligt tussen 0 en 100 procent.'));
        }

        // Het midden is "geen focuspunt": dan blijven naam en adres zoals ze waren.
        if ($x === 50 && $y === 50) {
            $x = $y = null;
        }

        $id = (int) $item->getKey();
        $item = MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->withTrashed()->find($id)
            ?? throw new MediaRejectedException(__('Dit bestand bestaat niet meer.'));
        $file = $item->file();

        if (! $file || ! MediaConversions::isConvertible((string) $file->mime_type)) {
            throw new MediaRejectedException(__('Alleen een afbeelding heeft een focuspunt.'));
        }

        $number = fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;
        $oldX = $number($item->getAttribute('focal_x'));
        $oldY = $number($item->getAttribute('focal_y'));

        if ($oldX === $x && $oldY === $y) {
            return $item;
        }

        // De formaten met het oude focuspunt blijven staan (browsers en het
        // CDN hebben ze nog); de opruimronde haalt ze na de bewaartermijn weg.
        $stale = (array) $file->getCustomProperty('stale_conversions', []);

        foreach (MediaConversions::libraryDefinitions($item->conversions, $oldX, $oldY, withAdminThumb: true) as $name => $definition) {
            if ($definition->isCropping() && $definition->withFocal($x, $y)->name !== $name && $file->hasGeneratedConversion($name)) {
                // Tot op de microseconde: de terugval op het leespad kiest
                // de laatst afgedankte uitsnede, ook bij twee wijzigingen kort na elkaar.
                $stale[$name] ??= now()->format('Y-m-d\TH:i:s.uP');
            }
        }

        // Stil: dit raakt het bestand van het item niet.
        $file->setCustomProperty('stale_conversions', $stale);
        $file->saveQuietly();

        // Het oude object draagt nog de oude conversion_urls: daar komen de namen vandaan van wat er gecachet staat.
        MediaItem::withoutGlobalScopes()->toBase()->where('id', $id)->update(['focal_x' => $x, 'focal_y' => $y, 'updated_at' => now()]);
        $this->invalidate($item, 'focal');
        MediaPickerFileCache::forgetConversions($id);

        // De pagina's die het beeld tonen moeten het nieuwe adres krijgen.
        rescue(fn () => \Dashed\DashedFiles\Jobs\FlushMediaDependentCaches::queueFor($id), report: false);

        rescue(fn () => $this->startConversionRun(Conversions\ConversionPump::FOCAL, ['ids' => [$id]], is_numeric(auth()->id()) ? (int) auth()->id() : null));

        return MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->withTrashed()->find($id) ?? $item;
    }

    /**
     * Bijsnijden, draaien en spiegelen. Het resultaat vervangt het huidige
     * bestand (nieuw adres); het onaangetaste bestand blijft als bron op
     * het item staan (collectie `library-source`) en elke volgende bewerking
     * begint daar opnieuw, dus zonder kwaliteitsverlies.
     *
     * @param  array<string, mixed>  $recipe  rotate (0, 90, 180, 270, met de klok mee), flip (h, v, hv) en crop (x, y, w, h in procenten)
     *
     * @throws MediaRejectedException
     */
    public function applyEdits(MediaItem $item, array $recipe): MediaItem
    {
        self::requireOwnEngine();

        if (! BrowseSchema::hasEdits()) {
            throw new MediaRejectedException(__('De kolom voor bewerkingen ontbreekt nog. Draai eerst de migraties.'));
        }

        $recipe = MediaEdits::validate($recipe);

        if (MediaEdits::isIdentity($recipe)) {
            return $this->sourceOf($item) ? $this->revertEdits($item) : $item;
        }

        $userId = is_numeric(auth()->id()) ? (int) auth()->id() : null;

        // De beeldbewerking draait in dit verzoek. Sterft het proces
        // halverwege (geheugen, time-out), dan draait `finally` niet: deze
        // haak ruimt dan de lokale kopieën op en geeft het slot vrij. Wat
        // ook dat mist haalt `dashed:clear-temp-images` weg.
        $guard = (object) ['paths' => [], 'done' => false];
        $itemId = (int) $item->getKey();

        register_shutdown_function(function () use ($guard, $itemId) {
            if ($guard->done) {
                return;
            }

            foreach ($guard->paths as $path) {
                @unlink($path);
            }

            rescue(fn () => Cache::lock("media-replace-{$itemId}")->forceRelease(), report: false);
        });

        try {
            return $this->swap($item, function (MediaItem $fresh, Media $current) use ($recipe, $userId, $guard) {
            $source = $this->sourceOf($fresh) ?? $current;
            $mime = strtolower((string) $source->mime_type);

            if (! in_array($mime, MediaEdits::MIMES, true)) {
                throw new MediaRejectedException(__('Alleen een jpeg, png of webp kan worden bewerkt.'));
            }

            // Vóór er iets wordt opgehaald of ingeladen: een foto van 48
            // megapixel kost de beeldbewerking honderden MB geheugen.
            $this->guardEditSize($this->dimension($source->getCustomProperty('original_width')), $this->dimension($source->getCustomProperty('original_height')));

            // Een lokale kopie van de bron; het resultaat staat ernaast.
            $local = IncomingMedia::fromDisk((string) $source->disk, $source->getPathRelativeToRoot(), 'upload');
            $guard->paths[] = $local->localPath;

            try {
                // Afmetingen die niet op de rij stonden: uit de kop van het bestand, nog zonder het in te laden.
                $size = @getimagesize($local->localPath);
                $this->guardEditSize($size ? (int) $size[0] : null, $size ? (int) $size[1] : null);

                $result = MediaEdits::render($local->localPath, $mime, $recipe);
                $guard->paths[] = $result;
            } finally {
                @unlink($local->localPath);
            }

            return [
                new IncomingMedia(
                    localPath: $result,
                    fileName: (string) $source->file_name,
                    source: 'upload',
                    folderId: $fresh->folder_id === null ? null : (int) $fresh->folder_id,
                    uploadedByUserId: $userId,
                    meta: ['edited_from' => (int) $source->getKey()],
                    allowedMimes: MediaEdits::MIMES,
                ),
                // Alleen de eerste keer wordt het huidige bestand de bron.
                $source->is($current) ? MediaItem::COLLECTION_SOURCE : MediaItem::COLLECTION_REPLACED,
            ];
            }, $recipe);
        } finally {
            $guard->done = true;
        }
    }

    /**
     * @throws MediaRejectedException
     */
    private function guardEditSize(?int $width, ?int $height): void
    {
        $limit = (float) config('dashed-files.edits.max_megapixels', 25);

        if ($limit <= 0 || ! $width || ! $height) {
            return;
        }

        $megapixels = ($width * $height) / 1_000_000;

        if ($megapixels > $limit) {
            throw new MediaRejectedException(__('Deze afbeelding is te groot om hier te bewerken: :megapixels megapixel, het maximum is :max. Bewerk hem op je computer en vervang het bestand.', [
                'megapixels' => (int) round($megapixels),
                'max' => (int) $limit,
            ]));
        }
    }

    private function dimension(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Het focuspunt, bewerken en de pomp bestaan alleen op de eigen motor:
     * op de oude registreert het pakket de formaten en kent het geen van drieën.
     *
     * @throws MediaRejectedException
     */
    private static function requireOwnEngine(): void
    {
        if (! self::usesOwnEngine()) {
            throw new MediaRejectedException(__('Dit werkt alleen op de eigen mediabibliotheek.'));
        }
    }

    /**
     * Zet het onaangetaste bestand terug als huidig bestand en wist het recept.
     *
     * @throws MediaRejectedException
     */
    public function revertEdits(MediaItem $item): MediaItem
    {
        self::requireOwnEngine();

        $id = (int) $item->getKey();
        $lock = Cache::lock("media-replace-{$id}", 120);

        if (! $lock->get()) {
            throw new MediaRejectedException(__('Dit bestand wordt op dit moment al vervangen. Probeer het zo opnieuw.'));
        }

        try {
            $item = MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->withTrashed()->find($id)
                ?? throw new MediaRejectedException(__('Dit bestand bestaat niet meer.'));
            $source = $this->sourceOf($item);
            $current = $item->file();

            if (! $source || ! $current) {
                throw new MediaRejectedException(__('Dit bestand is niet bewerkt: er is geen origineel om te herstellen.'));
            }

            // Staat het bronbestand niet meer op de schijf, dan zou herstellen
            // het item zonder bestand achterlaten: weigeren en niets wijzigen.
            $sourceDisk = \Illuminate\Support\Facades\Storage::disk((string) $source->disk);

            if (! rescue(fn () => $sourceDisk->exists($source->getPathRelativeToRoot()), false, false)) {
                throw new MediaRejectedException(__('Het originele bestand staat niet meer op de schijf en kan niet worden hersteld. De bewerkte afbeelding blijft staan.'));
            }

            $mirror = BrowseSchema::hasMirror() ? MediaMirror::attributesFor($source) : [];
            $checksum = $source->getCustomProperty('source_checksum');

            // Onbekend (een bron van voor de checksums): opnieuw berekenen,
            // anders houdt het item de checksum van de bewerkte afbeelding.
            if (blank($checksum)) {
                $checksum = rescue(function () use ($sourceDisk, $source) {
                    $stream = $sourceDisk->readStream($source->getPathRelativeToRoot());

                    if (! is_resource($stream)) {
                        return null;
                    }

                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);
                    fclose($stream);

                    return hash_final($hash);
                }, null, false);
            }

            DB::transaction(function () use ($item, $source, $current, $mirror, $checksum) {
                $rows = Media::query()->whereKey([$source->getKey(), $current->getKey()])->lockForUpdate()->pluck('collection_name', 'id');

                if (($rows[$source->getKey()] ?? null) !== MediaItem::COLLECTION_SOURCE || ($rows[$current->getKey()] ?? null) !== MediaItem::COLLECTION) {
                    throw new MediaRejectedException(__('Dit bestand is intussen door iemand anders vervangen of verwijderd. Laad de pagina opnieuw en probeer het nog eens.'));
                }

                $current->collection_name = MediaItem::COLLECTION_REPLACED;
                $current->setCustomProperty('replaced_at', now()->toIso8601String());
                $current->saveQuietly();

                $source->collection_name = MediaItem::COLLECTION;
                $source->forgetCustomProperty('source_checksum');
                $source->forgetCustomProperty('source_since');
                $source->saveQuietly();

                MediaItem::withoutGlobalScopes()->toBase()->where('id', $item->getKey())->update(array_merge(
                    $mirror,
                    // Altijd die van de bron, ook als hij niet te berekenen was: liever leeg dan die van een ander bestand.
                    app(MediaChecksums::class)->available() ? ['checksum' => filled($checksum) ? $checksum : null, 'size' => (int) $source->size] : [],
                    ['conversion_urls' => null, 'updated_at' => now()],
                    BrowseSchema::hasFocal() ? ['focal_x' => null, 'focal_y' => null] : [],
                    BrowseSchema::hasEdits() ? ['edits' => null] : [],
                ));
            });

            $this->invalidate($item, 'edits-reverted');
            MediaPickerFileCache::flush();

            $fresh = MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->withTrashed()->find($id) ?? $item;

            \Dashed\DashedFiles\Jobs\FlushMediaDependentCaches::queueFor($id, rewriteSources: true);

            event(new \Dashed\DashedFiles\Events\MediaReplaced($fresh, (int) $current->getKey(), (int) $source->getKey()));

            return $fresh;
        } finally {
            rescue(fn () => $lock->release(), report: false);
        }
    }

    /** De onaangetaste bron van een bewerkt item, of null als het niet bewerkt is. */
    public function sourceOf(MediaItem $item): ?Media
    {
        return Media::query()
            ->where('model_id', $item->getKey())
            ->whereIn('model_type', [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS])
            ->where('collection_name', MediaItem::COLLECTION_SOURCE)
            ->orderByDesc('id')
            ->first();
    }

    public const AVIF_SETTING = 'media_avif_enabled';

    private const AVIF_MEMO = 'dashed-files.media-avif';

    /**
     * Of AVIF aanstaat. Eén stand voor de hele installatie, op de eerste
     * site, standaard uit; `dashed-files.conversions.avif` (env
     * DASHED_MEDIA_AVIF) gaat erboven. Per verzoek onthouden, net als de motor.
     */
    public static function avifEnabled(): bool
    {
        $request = app()->bound('request') ? request() : null;

        if ($request && is_bool($memo = $request->attributes->get(self::AVIF_MEMO))) {
            return $memo;
        }

        $enabled = config('dashed-files.conversions.avif');

        if (! is_bool($enabled)) {
            try {
                $enabled = (bool) Customsetting::get(self::AVIF_SETTING, (string) Sites::getFirstSite()['id'], false);
            } catch (Throwable) {
                $enabled = false;
            }
        }

        $request?->attributes->set(self::AVIF_MEMO, $enabled);

        return $enabled;
    }

    public static function setAvifEnabled(bool $enabled): void
    {
        Customsetting::set(self::AVIF_SETTING, $enabled ? '1' : '0', (string) Sites::getFirstSite()['id']);
        self::forgetAvif();
    }

    public static function forgetAvif(): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove(self::AVIF_MEMO);
        }
    }

    public const TRASH_AUTO_PURGE_SETTING = 'media_trash_auto_purge';

    /**
     * Of de prullenbak zichzelf leegt na de bewaartermijn. Standaard uit;
     * aan via de configuratie (`dashed-files.trash.auto_purge`) of via de
     * instelling op de eerste site.
     */
    public static function trashAutoPurge(): bool
    {
        if (config('dashed-files.trash.auto_purge', false)) {
            return true;
        }

        try {
            return (bool) Customsetting::get(self::TRASH_AUTO_PURGE_SETTING, (string) Sites::getFirstSite()['id'], false);
        } catch (Throwable) {
            return false;
        }
    }

    public static function setTrashAutoPurge(bool $enabled): void
    {
        Customsetting::set(self::TRASH_AUTO_PURGE_SETTING, $enabled ? '1' : '0', (string) Sites::getFirstSite()['id']);
    }

    /**
     * Start een run van de pomp: `missing`, `force`, `focal`, `avif` of
     * `cleanup`. Hooguit één actieve run per soort; een tweede `missing` of
     * `focal` voegt zich bij de lopende, de andere worden geweigerd.
     *
     * @param  array{ids?: array<int, int|string>, names?: array<int, string>}  $filters
     *
     * @throws MediaRejectedException
     */
    public function startConversionRun(string $type, array $filters = [], ?int $userId = null): \Dashed\DashedFiles\Models\MediaConversionRun
    {
        return app(Conversions\ConversionPump::class)->start($type, $filters, $userId);
    }

    public function cancelConversionRun(\Dashed\DashedFiles\Models\MediaConversionRun|int $run): ?\Dashed\DashedFiles\Models\MediaConversionRun
    {
        return app(Conversions\ConversionPump::class)->cancel($run);
    }

    /**
     * @throws MediaRejectedException
     */
    public function resumeConversionRun(\Dashed\DashedFiles\Models\MediaConversionRun|int $run): \Dashed\DashedFiles\Models\MediaConversionRun
    {
        return app(Conversions\ConversionPump::class)->resume($run);
    }

    /**
     * Haalt een bestand van een URL en neemt het in. De download gaat altijd
     * door de wachter van dashed-core; zonder die wachter wordt er niets
     * opgehaald.
     *
     * @throws MediaRejectedException
     */
    public function importFromUrl(string $url, ?int $folderId = null, string $source = 'url', array $meta = [], array $options = []): IngestResult
    {
        return $this->ingest($this->download($url, $folderId, $source, $meta, $options));
    }

    /**
     * De download van importFromUrl() als losse stap, voor een aanroeper die
     * tussen ophalen en innemen nog iets moet beslissen. Het bestand is
     * daarna van de aanroeper: ingest() ruimt het op.
     *
     * @throws MediaRejectedException
     */
    public function download(string $url, ?int $folderId = null, string $source = 'url', array $meta = [], array $options = []): IncomingMedia
    {
        $downloader = 'Dashed\\DashedCore\\Http\\SafeDownloader';

        if (! class_exists($downloader) || ! method_exists($downloader, 'fetch')) {
            throw new MediaRejectedException(__('Een bestand van een URL ophalen kan pas na een update van dashed-core.'));
        }

        try {
            $downloaded = $downloader::fetch($url, $this->downloadOptions($source, $options));
        } catch (Throwable $exception) {
            if (is_a($exception, 'Dashed\\DashedCore\\Http\\DownloadRejectedException')) {
                throw new MediaRejectedException($exception->getMessage(), 0, $exception);
            }

            throw $exception;
        }

        return new IncomingMedia(
            localPath: $downloaded->path,
            fileName: $downloaded->suggestedName ?: 'download',
            source: $source,
            folderId: $folderId,
            meta: array_merge($meta, ['source_url' => $url]),
        );
    }

    /**
     * De opties van de downloader voor deze bron. Een aanroeper kan ze alleen
     * strenger maken: een vaste lijst hosts, een kortere time-out, minder
     * doorverwijzingen, een kleinere grens. Al het andere (http, poorten,
     * vertrouwde hosts) beslist IngestPolicy en niemand anders.
     *
     * @param  array{allowed_hosts?: list<string>, timeout?: int|float, max_redirects?: int, max_bytes?: int}  $narrow
     * @return array<string, mixed>
     */
    protected function downloadOptions(string $source, array $narrow = []): array
    {
        $options = IngestPolicy::downloadOptionsFor($source);

        // Een opgegeven lijst die na opschonen leeg is, weigert alles: nooit "geen beperking".
        if (array_key_exists('allowed_hosts', $narrow) && $narrow['allowed_hosts'] !== null) {
            $options['allowed_hosts'] = is_array($narrow['allowed_hosts'])
                ? array_values(array_filter($narrow['allowed_hosts'], fn ($host) => is_string($host) && trim($host) !== ''))
                : [];
        }

        // De time-out van de aanroeper is de totale tijd over alle sprongen samen.
        if (is_numeric($narrow['timeout'] ?? null) && $narrow['timeout'] > 0) {
            $options['timeout'] = min((float) $narrow['timeout'], (float) ($options['timeout'] ?? 20));
            $options['deadline'] = $options['timeout'];
        }

        if (is_numeric($narrow['max_redirects'] ?? null)) {
            $options['max_redirects'] = min(max(0, (int) $narrow['max_redirects']), (int) ($options['max_redirects'] ?? 3));
        }

        if (is_numeric($narrow['max_bytes'] ?? null) && $narrow['max_bytes'] > 0) {
            $options['max_bytes'] = min((int) $narrow['max_bytes'], (int) $options['max_bytes']);
        }

        return $options;
    }

    /**
     * De naam waaronder ingest() dit bestand zou bewaren, zonder het in te nemen.
     *
     * @throws MediaRejectedException
     */
    public function storedFileName(IncomingMedia $file): string
    {
        $name = is_file($file->localPath) ? IngestPolicy::fileName($file->fileName, IngestPolicy::sniff($file->localPath, $file->fileName)) : null;

        if ($name === null) {
            throw new MediaRejectedException(__('Het bestandstype is hier niet toegestaan.'));
        }

        return $name;
    }

    /**
     * Het bestaande item met dezelfde bytes. Standaard zonder de prullenbak;
     * met $onlyTrashed juist alleen wat daar ligt.
     */
    public function findByChecksum(string $sha256, ?int $size = null, bool $onlyTrashed = false, ?bool $private = null): ?MediaItem
    {
        if (! app(MediaChecksums::class)->available() || ($onlyTrashed && ! BrowseSchema::hasTrash())) {
            return null;
        }

        $candidates = ($onlyTrashed ? MediaItem::onlyTrashed() : MediaItem::query())
            ->when($size !== null, fn (Builder $query) => $query->where('size', $size))
            ->where('checksum', strtolower($sha256))
            // $private: alleen wat aan dezelfde kant van de grens staat. Null kijkt overal.
            ->when($private !== null && BrowseSchema::hasAccess(), fn (Builder $query) => $query->where('is_private', $private))
            ->orderBy('id')
            ->limit(25)
            ->get();

        // Een chatbijlage is van een bezoeker en verdwijnt met zijn gesprek:
        // een productfoto mag daar nooit naar gaan wijzen.
        return $candidates->first(function (MediaItem $item) {
            $source = $item->getAttribute('source') ?: $item->file()?->getCustomProperty('source');

            return $source !== 'chat';
        });
    }

    private function guardReason(string $path, string $storedName, string $mime): ?string
    {
        // Ook de eigen lijst van de bibliotheek: die is per project in te
        // stellen en zou anders pas bij het opslaan een fout geven.
        $blocked = (array) config('dashed-files.blocked_upload_extensions', MediaObserver::BLOCKED_UPLOAD_EXTENSIONS);
        $base = strtolower($storedName);

        if (in_array(ltrim($base, '.'), $blocked, true) || array_intersect(array_map('trim', array_slice(explode('.', $base), 1)), $blocked) !== []) {
            return __('Dit bestandstype is niet toegestaan.');
        }

        $rule = 'Dashed\\DashedCore\\Rules\\SafeUploadedFile';

        if (class_exists($rule) && method_exists($rule, 'rejectionReasonForPath')) {
            return $rule::rejectionReasonForPath($path, $storedName);
        }

        // Een oudere dashed-core kent de toets op een pad nog niet.
        return IngestPolicy::looksLikeServerCode((string) file_get_contents($path, false, null, 0, 64 * 1024), $mime)
            ? __('De inhoud van dit bestand is niet toegestaan.')
            : null;
    }

    /**
     * Een afbeelding van een paar honderd kB kan uitgepakt gigabytes vragen:
     * de beeldbewerking reserveert geheugen per pixel, niet per byte.
     *
     * @param  array{width: int, height: int}|null  $dimensions
     */
    private function guardPixelCount(?array $dimensions, string $source): void
    {
        if ($dimensions === null) {
            // Zonder afmetingen is er geen grens te trekken. Wat een bezoeker
            // of een app aanlevert komt er dan niet in.
            if (IngestPolicy::isUntrustedSource($source)) {
                throw new MediaRejectedException(__('De afmetingen van deze afbeelding zijn niet te lezen.'));
            }

            return;
        }

        $max = IngestPolicy::maxMegapixels($source);

        if ($dimensions['width'] * $dimensions['height'] > $max * 1_000_000) {
            throw new MediaRejectedException(__('Deze afbeelding heeft te veel pixels (maximaal :max megapixel).', ['max' => rtrim(rtrim(number_format($max, 3, ',', ''), '0'), ',')]));
        }
    }

    private function queueHashing(): void
    {
        // Elke upload kan hier langskomen; één taak per uur is genoeg, die schuift zichzelf door.
        if (Cache::add('media-library-hashing-queued', true, 3600)) {
            HashMediaItems::dispatch();
        }
    }

    /**
     * Zet de spiegelkolommen van het item (bestandsnaam, type, grootte,
     * afmetingen, bron) gelijk aan zijn mediarij, met één stille update.
     * De observer op de mediarij roept dit aan voor elke weg waarlangs een
     * bestand binnenkomt of verandert; $media bespaart daar de query.
     */
    public function syncMirror(MediaItem|int $item, ?Media $media = null): void
    {
        app(MediaMirror::class)->sync($item, $media);
    }

    /**
     * @param  MediaItem|array<int, MediaItem|int>  $items
     * @param  array<int, string>  $names
     * @param  string  $mode  replace|add|remove
     */
    public function setTags(MediaItem|array $items, array $names, string $mode = 'replace'): void
    {
        app(MediaTagging::class)->setTags($items, $names, $mode);
    }

    /**
     * @return array<int, array{id: int, name: string, count: int}>
     */
    public function allTags(?string $search = null, int $limit = 50): array
    {
        return app(MediaTagging::class)->allTags($search, $limit);
    }

    public function toggleFavorite(int $itemId, int $userId): bool
    {
        return app(MediaTagging::class)->toggleFavorite($itemId, $userId);
    }

    public function setFavorite(array $itemIds, int $userId, bool $on): void
    {
        app(MediaTagging::class)->setFavorite($itemIds, $userId, $on);
    }

    public function conversionsOf(int|string $id): ?string
    {
        if (! $this->isId($id)) {
            return null;
        }

        return MediaItem::withoutGlobalScopes()->toBase()->where('id', (int) $id)->value('conversions');
    }

    public function clearConversionUrls(int|array|null $ids = null): void
    {
        // Via toBase(): geen modellen, geen events en updated_at blijft staan.
        $query = MediaItem::withoutGlobalScopes()->toBase();

        if ($ids !== null) {
            $ids = $this->ids((array) $ids);

            if ($ids === []) {
                return;
            }

            $query->whereIn('id', $ids);
        }

        $query->update(['conversion_urls' => null]);
    }

    /**
     * De ene ingang voor "dit item is veranderd": wist de opgeslagen url's,
     * elk gecachet resultaat en de kopie in het geheugen van dit proces.
     * Raakt het item zelf niet aan, dus vuurt geen events en is veilig om
     * vanuit een observer aan te roepen.
     *
     * Een modelobject (het eigen model of dat van het pakket) mag ook al
     * verwijderd zijn: dan komen de namen van zijn attributen.
     */
    public function invalidate(Model|int $item, string $reason = 'updated'): void
    {
        // $reason doet nog niets: het staat in de handtekening zodat
        // aanroepers nu al zeggen waarom, voor als een latere stap er een
        // event of een CDN-purge aan hangt.
        $id = $item instanceof Model ? (int) $item->getKey() : $item;

        if ($id <= 0) {
            return;
        }

        $row = MediaItem::withoutGlobalScopes()->toBase()->where('id', $id)->first(['conversions', 'conversion_urls']);

        $names = array_merge(
            self::ALWAYS_CACHED,
            $this->cachedNames($row?->conversions, $row?->conversion_urls),
            $item instanceof Model ? $this->cachedNames($item->getAttribute('conversions'), $item->getAttribute('conversion_urls')) : [],
        );

        if ($row?->conversion_urls !== null) {
            $this->clearConversionUrls($id);
        }

        foreach (array_unique($names) as $name) {
            Cache::forget(self::cacheKey($id, $name));
        }

        MediaHelper::forgetInMemory($id);
    }

    /**
     * Alleen het gecachete resultaat van deze items, de opgeslagen url's
     * blijven staan. Voor weggooien en terugzetten: de payload in de cache
     * draagt of het item in de prullenbak ligt, het adres zelf verandert niet.
     * Bij veel items gaat de hele cache om in plaats van een query per item.
     *
     * @param  array<int, int|string>  $ids
     */
    public function forgetCachedFor(array $ids): void
    {
        $ids = $this->ids($ids);

        if ($ids === []) {
            return;
        }

        if (count($ids) > 50) {
            $this->forgetAllCached();

            return;
        }

        $rows = MediaItem::withoutGlobalScopes()->toBase()->whereIn('id', $ids)->get(['id', 'conversions', 'conversion_urls']);

        foreach ($rows as $row) {
            foreach (array_unique(array_merge(self::ALWAYS_CACHED, $this->cachedNames($row->conversions, $row->conversion_urls))) as $name) {
                Cache::forget(self::cacheKey($row->id, $name));
            }

            MediaHelper::forgetInMemory((int) $row->id);
        }
    }

    /**
     * Maakt elk gecachet resultaat van elk item ongeldig. Per sleutel kan
     * dat niet (de cache is niet te doorzoeken), dus schuift de generatie op
     * waar elke sleutel mee begint. De oude sleutels blijven in de cache
     * staan tot die ze zelf opruimt; ze worden niet meer gelezen.
     */
    public function invalidateAll(): void
    {
        $this->clearConversionUrls();
        $this->forgetAllCached();
    }

    /**
     * Alleen de cache, de opgeslagen url's blijven staan. Voor een
     * wijziging over veel items die de url's niet raakt, zoals alt-teksten.
     */
    public function forgetAllCached(): void
    {
        $current = Cache::get(self::GENERATION_KEY);
        // Hoger dan wat de cache kent, dan wat dit proces al gebruikte en dan de klok.
        $next = max(is_int($current) ? $current + 1 : 0, (self::$generation ?? 0) + 1, self::clock());

        MediaHelper::flushMemoryCache();

        Cache::forever(self::GENERATION_KEY, $next);
        self::$generation = $next;
    }

    /**
     * Zet de teller van mislukte AI-pogingen terug, zodat de AI het voor deze
     * items opnieuw mag proberen. Zonder id's: voor elk item dat op het
     * maximum staat. Een query en geen save: de teller zit niet in de cache.
     *
     * @param  array<int, int|string>|null  $ids
     */
    public function resetAltTries(?array $ids = null): int
    {
        $query = MediaItem::withoutGlobalScopes()->toBase()->where('automatic_alt_tries', '>', 0);

        if ($ids !== null) {
            $ids = $this->ids($ids);

            if ($ids === []) {
                return 0;
            }

            $query->whereIn('id', $ids);
        }

        return $query->update(['automatic_alt_tries' => 0]);
    }

    /**
     * De herstelronde voor wat langs de haken is geglipt (een massa-update
     * via de querybuilder, een import rechtstreeks in de tabel): zet voor
     * elke rij waar de tekstkolom en de spiegeltaal in de vertalingen
     * verschillen de vertalingen gelijk aan de tekstkolom, en schrijft een
     * kolom met ongeldige of anders opgemaakte JSON opnieuw. Met $write op
     * false wordt alleen geteld.
     *
     * Weigert als de vastgelegde spiegeltaal geen actieve taal meer is: de
     * tekstkolom zou dan onder een taal terechtkomen die de site niet kent,
     * of, erger, als een andere taal gelezen worden. Wissel eerst bewust met
     * switchTextMirror().
     *
     * @return int het aantal afwijkende rijen
     */
    public function syncTexts(bool $write = true, int $chunk = 500, bool $force = false): int
    {
        if (! MediaTexts::hasColumns()) {
            return 0;
        }

        if ($write && ! $force && ! MediaTexts::isLocale(MediaTexts::firstLocale())) {
            throw new InvalidArgumentException('The stored mirror locale [' . MediaTexts::firstLocale() . '] is not an active locale. Switch it first with dashed:media-texts --mirror=<locale>.');
        }

        $columns = ['id'];

        foreach (MediaTexts::FIELDS as [$column, $json]) {
            array_push($columns, $column, $json);
        }

        $drifted = 0;
        $touched = false;

        // Alleen rijen waar iets staat: een item zonder enige tekst kan niet afwijken.
        MediaItem::withoutGlobalScopes()->toBase()
            ->where(function ($query) {
                foreach (MediaTexts::FIELDS as [$column, $json]) {
                    $query->orWhereNotNull($column)->orWhereNotNull($json);
                }
            })
            ->select($columns)
            ->chunkById($chunk, function ($rows) use (&$drifted, &$touched, $write) {
                foreach ($rows as $row) {
                    $update = [];

                    foreach (MediaTexts::FIELDS as $name => [$column, $json]) {
                        $wanted = MediaTexts::encode(MediaTexts::fromRow($name, $row->{$column}, $row->{$json}));

                        // Ook een lege tekst, ongeldige JSON of JSON met andere opmaak: de
                        // zoek- en lijstquery's lezen de kolom als tekst.
                        if ($row->{$json} !== $wanted) {
                            $update[$json] = $wanted;
                        }

                        // Een tekstkolom met alleen witruimte is leeg.
                        if ($row->{$column} !== null && $row->{$column} !== '' && trim((string) $row->{$column}) === '') {
                            $update[$column] = null;
                        }
                    }

                    if ($update === []) {
                        continue;
                    }

                    $drifted++;

                    if ($write) {
                        MediaItem::withoutGlobalScopes()->toBase()->where('id', $row->id)->update($update);
                        $touched = true;
                    }
                }
            });

        if ($touched) {
            $this->forgetAllCached();
        }

        return $drifted;
    }

    /**
     * Wisselt bewust van spiegeltaal. Eerst komt alles wat nog alleen in de
     * tekstkolommen staat onder de OUDE spiegeltaal in de vertalingen;
     * daarna worden de tekstkolommen herschreven uit de vertalingen (de
     * vertalingen winnen) voor de nieuwe taal, in porties, en pas dan gaat de
     * instelling om. Geen enkele vertaling gaat verloren.
     *
     * @return int het aantal rijen waarvan een tekstkolom veranderde
     */
    public function switchTextMirror(string $locale, int $chunk = 500): int
    {
        if (! MediaTexts::hasColumns()) {
            throw new InvalidArgumentException('The translation columns are missing. Run the migrations first.');
        }

        if (! MediaTexts::isLocale($locale)) {
            throw new InvalidArgumentException("[{$locale}] is not an active locale of this installation.");
        }

        // Onder de oude spiegeltaal, ook als die niet meer actief is.
        $this->syncTexts(write: true, chunk: $chunk, force: true);

        $columns = ['id'];

        foreach (MediaTexts::FIELDS as [$column, $json]) {
            array_push($columns, $column, $json);
        }

        $changed = 0;

        MediaItem::withoutGlobalScopes()->toBase()
            ->where(function ($query) {
                foreach (MediaTexts::FIELDS as [$column, $json]) {
                    $query->orWhereNotNull($column)->orWhereNotNull($json);
                }
            })
            ->select($columns)
            ->chunkById($chunk, function ($rows) use (&$changed, $locale) {
                foreach ($rows as $row) {
                    $update = [];

                    foreach (MediaTexts::FIELDS as [$column, $json]) {
                        $value = MediaTexts::decode($row->{$json})[$locale] ?? null;

                        if ($row->{$column} !== $value) {
                            $update[$column] = $value;
                        }
                    }

                    if ($update !== []) {
                        MediaItem::withoutGlobalScopes()->toBase()->where('id', $row->id)->update($update);
                        $changed++;
                    }
                }
            });

        MediaTexts::storeMirrorLocale($locale);
        $this->forgetAllCached();

        return $changed;
    }

    /**
     * Leegt alles wat dit proces in het geheugen vasthoudt. Voor processen
     * die langer leven dan één verzoek, zoals een wachtrijwerker.
     */
    public function flushRuntime(): void
    {
        MediaHelper::flushMemoryCache();
    }

    /**
     * De generatie wordt per proces onthouden; een proces dat langer leeft
     * moet hem opnieuw uit de cache kunnen lezen.
     */
    public static function forgetGeneration(): void
    {
        self::$generation = null;
    }

    /**
     * Elke formaatnaam waaronder voor een item iets in de cache kan staan:
     * de gevraagde formaten en alles waar een url van is opgeslagen. Dat
     * laatste dekt wat niet in `conversions` komt, zoals een svg die met
     * een formaat is opgevraagd en als origineel is uitgeleverd.
     *
     * @return array<int, string>
     */
    private function cachedNames(mixed $conversions, mixed $conversionUrls): array
    {
        $names = [];
        $requested = is_string($conversions) ? json_decode($conversions, true) : $conversions;

        foreach (is_array($requested) ? $requested : [] as $conversion) {
            if (is_string($conversion) || is_array($conversion)) {
                $names[] = ConversionDefinition::nameOf($conversion) ?: 'original';
            }
        }

        $urls = is_string($conversionUrls) ? json_decode($conversionUrls, true) : $conversionUrls;

        if (is_array($urls)) {
            // Ook de sleutels buiten het omhulsel: oudere code cachete onder dezelfde namen.
            $names = array_merge($names, array_keys(is_array($urls['urls'] ?? null) ? $urls['urls'] : []), array_diff(array_keys($urls), ['v', 'urls']));
        }

        return array_map('strval', $names);
    }

    /**
     * Waar een item gebruikt wordt, uit de gebruiksindex.
     *
     * @return array<int, array{label: string, title: string, field: string, locale: ?string, url: ?string, trashed: bool}>
     */
    public function usagesOf(MediaItem|int $item, int $limit = 100): array
    {
        return app(\Dashed\DashedFiles\Classes\Usage\MediaUsages::class)->of($item instanceof MediaItem ? (int) $item->getKey() : $item, $limit);
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int> item-id => aantal verwijzingen, in één query
     */
    public function usageCount(array $ids): array
    {
        return app(\Dashed\DashedFiles\Classes\Usage\MediaUsages::class)->counts($this->ids($ids));
    }

    /**
     * Voegt dubbele bestanden samen: elke ZEKERE verwijzing naar een van de
     * anderen gaat naar $keep, en de anderen gaan naar de prullenbak. Een
     * duplicaat waar nog een gegokte of niet om te zetten verwijzing naar
     * wijst blijft buiten de prullenbak. Wat is omgezet staat per verwijzing
     * in `dashed__media_merges` en is met undoMerge() terug te draaien; de
     * overgenomen alt-tekst, het bijschrift en de tags blijven op $keep staan.
     *
     * Weigert zolang de gebruiksindex niet gevuld is: zonder index is niet
     * te weten waar de duplicaten gebruikt worden. Bij meer dan
     * MediaDuplicates::SYNC_LIMIT modellen loopt het als wachtrijtaak en is
     * `queued` true.
     *
     * @param  array<int, int|string>  $otherIds
     * @return array{batch: string, queued: bool, converted: int, failed: int, trashed: array<int, int>, kept_out: array<int, int>}
     */
    public function mergeDuplicates(MediaItem $keep, array $otherIds, ?int $userId = null): array
    {
        return app(\Dashed\DashedFiles\Classes\Usage\MediaDuplicates::class)->merge($keep, $this->ids($otherIds), $userId);
    }

    /**
     * Draait een samenvoeging terug: de omgezette verwijzingen wijzen weer
     * naar het samengevoegde item, dat zo nodig uit de prullenbak komt.
     *
     * @return array{restored: int, failed: int, items: array<int, int>, missing: array<int, int>}
     */
    public function undoMerge(string $batch): array
    {
        return app(\Dashed\DashedFiles\Classes\Usage\MediaDuplicates::class)->undo($batch);
    }

    /**
     * Of dit item afgeschermd is: het staat in een privé-map (of in een map
     * daaronder), of zijn bestanden zijn nog onderweg van of naar de
     * privé-schijf. Het leespad van de site (getSingleMedia) geeft voor zo'n
     * item niets terug.
     */
    public function isPrivate(?MediaItem $item): bool
    {
        return \Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($item);
    }

    /**
     * Een ondertekende, kortlevende link naar een privé-bestand in het
     * beheer. Voor een veld met `->allowPrivate()`: de opgeslagen waarde is
     * een id, en wie dat uitleest vraagt hier zelf het adres op.
     *
     * LET OP: deze methode controleert GEEN recht. Ze geeft alleen een
     * ondertekende link; wie hem opent moet op dat moment een ingelogde
     * paneelgebruiker met het recht `view_media` zijn, anders weigert de
     * route. De link is dus niet bruikbaar voor een bezoeker van de site.
     * Wil een project een privé-bestand aan iemand anders uitleveren (een
     * klant in zijn account, een bijlage in een mail), dan doet het dat zelf
     * achter een eigen controle, met privateFile() voor de schijf en het pad.
     *
     * Voor een item dat niet privé is geeft dit null: dat heeft een gewoon adres.
     *
     * @param  int  $minutes  hoe lang de link geldig is; standaard `dashed-files.private.link_minutes` (15)
     * @param  string|null  $conversion  `original` (standaard), `thumb`, `medium`, `preview` of `source`
     */
    public static function privateUrl(MediaItem $item, ?int $minutes = null, ?string $conversion = null, bool $download = false): ?string
    {
        $variant = $conversion ?? 'original';

        if (! in_array($variant, \Dashed\DashedFiles\Http\Controllers\MediaPrivateFileController::VARIANTS, true)
            || ! \Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($item)) {
            return null;
        }

        $minutes = max(1, min(24 * 60, $minutes ?? (int) config('dashed-files.private.link_minutes', 15)));

        return rescue(function () use ($item, $minutes, $variant, $download) {
            $panel = \Filament\Facades\Filament::getCurrentOrDefaultPanel();

            return \Illuminate\Support\Facades\URL::temporarySignedRoute(
                $panel->generateRouteName(\Dashed\DashedFiles\Http\Controllers\MediaPrivateFileController::ROUTE),
                now()->addMinutes($minutes),
                array_merge(['item' => (int) $item->getKey(), 'variant' => $variant], $download ? ['download' => 1] : []),
            );
        }, null, false);
    }

    /**
     * Waar een privé-bestand staat, voor code die het zelf uitlevert achter
     * een eigen controle. Controleert zelf GEEN recht. Null als het item
     * niet privé is, het bestand er niet is of de variant niet bestaat.
     *
     * `thumb` en `medium` vallen voor een afbeelding terug op het origineel
     * zolang het formaat niet gegenereerd is; `preview` is de poster van een
     * video of de miniatuur van een pdf.
     *
     * @return array{disk: string, path: string, mime: string, name: string, size: ?int}|null
     */
    public function privateFile(MediaItem $item, string $variant = 'original'): ?array
    {
        if (! in_array($variant, \Dashed\DashedFiles\Http\Controllers\MediaPrivateFileController::VARIANTS, true)
            || ! \Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($item)) {
            return null;
        }

        $clean = fn (?string $name) => str_replace(['/', '\\', "\0", "\r", "\n", '%'], '-', (string) $name) ?: 'bestand';
        $describe = fn (Media $media) => [
            'disk' => (string) $media->disk,
            'path' => $media->getPathRelativeToRoot(),
            'mime' => (string) $media->mime_type,
            'name' => $clean($media->file_name),
            'size' => is_numeric($media->size) ? (int) $media->size : null,
        ];

        if ($variant === 'preview') {
            $preview = $item->preview();

            return $preview ? $describe($preview) : null;
        }

        // De onaangetaste bron van een bewerkte afbeelding, voor het bewerkvenster.
        if ($variant === 'source') {
            $source = $this->sourceOf($item);

            return $source ? $describe($source) : null;
        }

        $file = $item->file();

        if (! $file) {
            return null;
        }

        if ($variant === 'original') {
            return $describe($file);
        }

        $mime = strtolower((string) $file->mime_type);

        if (! str_starts_with($mime, 'image/')) {
            return null;
        }

        $focal = self::usesOwnEngine() ? $item->focalPoint() : null;
        $thumbs = [MediaConversions::thumbName($focal['x'] ?? null, $focal['y'] ?? null), 'thumb', 'medium'];
        $names = match ($variant) {
            'thumb' => array_unique($thumbs),
            // De beheerminiatuur, dan de terugval van de gewone.
            'admin-thumb' => array_unique(array_merge([MediaConversions::adminThumbName($focal['x'] ?? null, $focal['y'] ?? null), ConversionDefinition::ADMIN_THUMB], $thumbs)),
            default => ['medium'],
        };

        foreach ($names as $name) {
            if (! $file->hasGeneratedConversion($name)) {
                continue;
            }

            $path = rescue(fn () => $file->getPathRelativeToRoot($name), null, false);

            if (is_string($path) && $path !== '') {
                return [
                    'disk' => (string) ($file->conversions_disk ?: $file->disk),
                    'path' => $path,
                    'mime' => self::mimeFromExtension($path),
                    'name' => $clean(basename($path)),
                    'size' => null,
                ];
            }
        }

        // Een svg of gif heeft geen formaten, en van een nieuwe afbeelding zijn ze er nog niet.
        return $describe($file);
    }

    private static function mimeFromExtension(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    public const PURGE_PERMISSION = 'purge_media';

    /**
     * Of deze gebruiker bestanden definitief mag verwijderen en samenvoegen.
     * Een echt recht: een superadmin heeft het via Gate::before, een rol
     * krijgt het bij Gebruikers, Rollen. Niemand krijgt het vanzelf.
     */
    public static function mayPurge(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows(self::PURGE_PERMISSION);
    }

    /**
     * De toets op het model zelf, voor elke weg naar een definitieve
     * verwijdering. Zonder ingelogde gebruiker (een commando, de opruimer,
     * een wachtrijtaak) valt er niemand te toetsen.
     */
    public static function authorizePurge(): void
    {
        $user = auth()->user();

        if ($user !== null && ! self::mayPurge($user)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(__('Je hebt geen recht om bestanden definitief te verwijderen.'));
        }
    }

    /**
     * De ene plek die beslist of een paneelgebruiker iets mag in de
     * mediaschermen. De handelingen: `view`, `upload`, `edit`, `delete`,
     * `folders`, `manage` (lezen dat alleen de beheerpagina doet) en `purge`.
     * Zie Access\MediaRights voor het recht achter elke handeling.
     *
     * Dit gaat over schermen. ingest(), uploadFromPath() en alles wat zonder
     * paneelgebruiker loopt (chat, API, AI-import, een commando, een
     * wachtrijtaak) vraagt hier niets.
     */
    public static function allows(string $ability, ?Authenticatable $user = null): bool
    {
        return \Dashed\DashedFiles\Classes\Access\MediaRights::allows($ability, $user ?? \Dashed\DashedFiles\Classes\Access\MediaRights::user());
    }

    public function canView(?Authenticatable $user): bool
    {
        return self::allows('view', $user);
    }

    public function canUpload(?Authenticatable $user): bool
    {
        return $user !== null && self::allows('upload', $user);
    }

    public function canManageFolders(?Authenticatable $user): bool
    {
        return $user !== null && self::allows('folders', $user);
    }

    /**
     * Bewerken van dit item: het recht `edit_media`, en daarbovenop de
     * policy van het project als die er is.
     */
    public function canUpdate(?Authenticatable $user, MediaItem $item): bool
    {
        return $user !== null && self::allows('edit', $user) && $this->policyAllows('update', $user, $item);
    }

    /** Weggooien of terugzetten van dit item: het recht `delete_media`, en de policy als die er is. */
    public function canDelete(?Authenticatable $user, MediaItem $item): bool
    {
        return $user !== null && self::allows('delete', $user) && $this->policyAllows('delete', $user, $item);
    }

    /**
     * Of er voor items een policy bestaat. Zonder policy mag alles en hoeft
     * een bulkbewerking de items niet eerst op te halen om het te vragen.
     */
    public function hasItemPolicy(): bool
    {
        return Gate::getPolicyFor(MediaItem::class) !== null
            || (\Dashed\DashedFiles\Classes\Legacy\LegacyClassAliases::isPackageClass(MediaItem::LEGACY_CLASS) && Gate::getPolicyFor(MediaItem::LEGACY_CLASS) !== null);
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function canUpdateAll(?Authenticatable $user, array $ids): bool
    {
        // Het recht zelf, ook zonder policy: een aanroeper die alleen dit vraagt krijgt anders altijd ja.
        if ($user === null || ! self::allows('edit', $user)) {
            return false;
        }

        if (! $this->hasItemPolicy()) {
            return true;
        }

        foreach ($this->findMany($ids) as $item) {
            if (! $this->canUpdate($user, $item)) {
                return false;
            }
        }

        return true;
    }

    private function policyAllows(string $ability, ?Authenticatable $user, MediaItem $item): bool
    {
        if (Gate::getPolicyFor(MediaItem::class)) {
            return Gate::forUser($user)->allows($ability, $item);
        }

        $legacy = MediaItem::LEGACY_CLASS;

        // Alleen de echte pakketklasse: is het pakket weg, dan is de oude naam
        // ons model en heeft forwardLegacyPolicies() de policy al op MediaItem gezet.
        if (\Dashed\DashedFiles\Classes\Legacy\LegacyClassAliases::isPackageClass($legacy) && Gate::getPolicyFor($legacy)) {
            // Een bestaande policy is op de pakketklasse getypeerd, dus die
            // krijgt dezelfde rij als instantie van die klasse.
            return Gate::forUser($user)->allows($ability, (new $legacy())->newFromBuilder($item->getAttributes()));
        }

        return true;
    }

    /**
     * De ene schrijfingang voor alt-tekst, bijschrift en titel. De taal is
     * altijd expliciet: in het paneel is de taal van de applicatie die van de
     * beheerder, niet die van de inhoud.
     */
    public function setText(MediaItem $item, string $field, string $locale, ?string $value): void
    {
        $this->setTexts($item, $field, [$locale => $value]);
    }

    /**
     * Meerdere talen in één save. Een lege waarde haalt de taal weg. De
     * tekstkolom (de spiegel van de spiegeltaal) en de vertalingen gaan
     * samen de database in.
     *
     * De rij wordt binnen een transactie opnieuw gelezen en vergrendeld, en
     * alleen de gevraagde talen worden samengevoegd: het model van de
     * aanroeper kan seconden tot minuten oud zijn (een AI-taak wacht op zijn
     * antwoord), en wat een redacteur intussen in een andere taal of een
     * ander veld opsloeg mag daar niet door verdwijnen.
     *
     * @param  array<string, ?string>  $perLocale
     * @param  bool  $onlyEmpty  een taal die op de vers gelezen rij al gevuld is blijft staan
     * @return array<int, string> de talen die echt geschreven zijn
     */
    public function setTexts(MediaItem $item, string $field, array $perLocale, bool $onlyEmpty = false): array
    {
        $name = MediaTexts::field($field);

        if ($name === null || ! in_array($field, self::TEXT_FIELDS, true)) {
            throw new InvalidArgumentException("Unknown media text field [{$field}].");
        }

        foreach ($perLocale as $locale => $value) {
            if (! is_string($locale) || ! MediaTexts::looksLikeLocale($locale)) {
                throw new InvalidArgumentException('Invalid media text locale.');
            }

            // De spiegelkolommen zijn varchar(255); elke taal volgt die grens.
            if ($value !== null && mb_strlen($value) > MediaTexts::MAX_LENGTH) {
                throw new MediaTextTooLongException(MediaTexts::MAX_LENGTH);
            }
        }

        if ($perLocale === [] || ! $item->exists) {
            return [];
        }

        $written = DB::transaction(function () use ($item, $name, $perLocale, $onlyEmpty) {
            $columns = [];

            foreach (MediaTexts::FIELDS as [$column, $json]) {
                $columns[] = $column;

                if (MediaTexts::hasColumns()) {
                    $columns[] = $json;
                }
            }

            $fresh = MediaItem::withoutGlobalScopes()->toBase()->where('id', $item->getKey())->lockForUpdate()->first($columns);

            if (! $fresh) {
                return [];
            }

            // De teksten op het model zijn vanaf hier die van de database.
            // Andere attributen van het model blijven zoals de aanroeper ze had.
            $item->setRawAttributes(array_merge($item->getAttributes(), (array) $fresh));
            $item->syncOriginalAttributes($columns);

            $first = MediaTexts::firstLocale();
            $translations = MediaTexts::of($item, $name);
            $written = [];

            foreach ($perLocale as $locale => $value) {
                $empty = $value === null || trim($value) === '';

                if ($onlyEmpty && isset($translations[$locale])) {
                    continue;
                }

                if ($empty && ! isset($translations[$locale])) {
                    continue;
                }

                if ($empty) {
                    unset($translations[$locale]);
                } elseif (($translations[$locale] ?? null) === $value) {
                    continue;
                } else {
                    $translations[$locale] = $value;
                }

                $written[] = $locale;
            }

            if ($written === []) {
                return [];
            }

            $item->setAttribute(MediaTexts::column($name), $translations[$first] ?? null);

            // Tussen deploy en migrate bestaat alleen de tekstkolom: dan komt de spiegeltaal daar en wacht de rest.
            if (MediaTexts::hasColumns()) {
                $item->setAttribute(MediaTexts::jsonColumn($name), MediaTexts::encode($translations));
            }

            $item->save();

            return $written;
        });

        if ($written !== []) {
            $this->invalidate($item, 'text');
        }

        return $written;
    }

    private function isId(int|string|null $id): bool
    {
        return is_int($id) || (is_string($id) && ctype_digit($id));
    }

    /**
     * @return array<int, int>
     */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, fn ($id) => $this->isId($id)))));
    }
}
