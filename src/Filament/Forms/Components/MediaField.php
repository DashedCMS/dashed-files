<?php

namespace Dashed\DashedFiles\Filament\Forms\Components;

use Closure;
use Throwable;
use Filament\Forms\Components\Field;
use Dashed\DashedFiles\Models\MediaItem;
use Filament\Notifications\Notification;
use Dashed\DashedFiles\Classes\MediaTexts;
use Dashed\DashedFiles\Models\MediaFolder;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Classes\MediaFieldItems;
use Dashed\DashedFiles\Classes\Access\MediaSites;
use Dashed\DashedFiles\Classes\Access\MediaVisibility;
use Dashed\DashedFiles\Classes\Browse\MediaItemPayload;
use Dashed\DashedFiles\Filament\Pages\MediaLibraryPage;
use Dashed\DashedFiles\Classes\Access\LockedFieldStates;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;

/**
 * Het mediaveld van de eigen mediabibliotheek. De staat is een id (enkel)
 * of een lijst id's (meervoudig), onversleuteld; het kiezen zelf gebeurt in
 * het keuzevenster (Livewire\MediaPickerModal), dat de selectie via
 * applySelection() terugmeldt. Alles wat de browser stuurt wordt hier
 * opnieuw gecontroleerd: bestaat het, past het type, past het aantal.
 *
 * Opslaan levert de vorm die de kiezer van het pakket ook opleverde (id's als
 * tekst, een leeg meervoudig veld als null), zodat opslaan zonder wijziging
 * de opgeslagen inhoud niet verandert.
 */
class MediaField extends Field
{
    public const MAX_SELECTION = 500;

    public const ALT_MAX = 255;

    public const EXTERNAL_MAX = 2048;

    private const SESSION_KEY = 'dashed-media.stored-text';

    protected string $view = 'dashed-files::filament.forms.media-field';

    protected bool | Closure $isMultiple = false;

    protected bool | Closure $isReorderable = true;

    protected bool | Closure $isDownloadable = true;

    protected int | Closure | null $maxFiles = null;

    protected int | Closure | null $minFiles = null;

    /** @var array<int, string>|Closure|null */
    protected array | Closure | null $acceptedFileTypes = null;

    protected int | string | MediaFolder | Closure | null $defaultFolder = null;

    protected bool | Closure $allowsPrivate = false;

    /** @var array<int, string>|bool|Closure|null null is elke beschikbare bron, false geen enkele */
    protected array | bool | Closure | null $sources = null;

    /** @var array<int, string>|string|Closure|false|null false is "geen sitefilter", null is de standaard */
    protected array | string | Closure | bool | null $forSites = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rule(static fn (MediaField $component): Closure => $component->getStateRule());
    }

    // ---------- Instellingen ----------

    public function multiple(bool | Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function reorderable(bool | Closure $condition = true): static
    {
        $this->isReorderable = $condition;

        return $this;
    }

    /**
     * Of de knop "openen in een nieuw tabblad" bij een item staat.
     */
    public function downloadable(bool | Closure $condition = true): static
    {
        $this->isDownloadable = $condition;

        return $this;
    }

    public function maxFiles(int | Closure | null $count): static
    {
        $this->maxFiles = $count;

        return $this;
    }

    public function minFiles(int | Closure | null $count): static
    {
        $this->minFiles = $count;

        return $this;
    }

    /**
     * @param  array<int, string>|string|Closure|null  $types  mimetypes, met joker (image/*) of exact (application/pdf)
     */
    public function acceptedFileTypes(array | string | Closure | null $types): static
    {
        $this->acceptedFileTypes = is_string($types) ? [$types] : $types;

        return $this;
    }

    public function image(bool $condition = true): static
    {
        return $this->acceptedFileTypes($condition ? ['image/*'] : null);
    }

    /**
     * De map waarin het venster opent en waar een gesleept bestand
     * terechtkomt: een pad ("producten/2026"), een map-id of een map.
     */
    public function defaultFolder(int | string | MediaFolder | Closure | null $folder): static
    {
        $this->defaultFolder = $folder;

        return $this;
    }

    // De kiezer van het pakket kende deze instellingen; hier hebben ze geen
    // betekenis meer. Ze bestaan zodat bestaande aanroepen blijven werken.

    /**
     * Laat dit veld ook bestanden uit een privé-map kiezen. Standaard toont
     * het keuzevenster die niet en weigert het veld ze: een privé-bestand
     * heeft op de site geen adres (getSingleMedia() geeft er niets voor terug).
     *
     * De opgeslagen waarde blijft een id. Wie die uitleest vraagt het adres
     * zelf op met MediaLibrary::privateUrl($item) (een ondertekende link voor
     * het beheer) of levert het bestand zelf uit met MediaLibrary::privateFile().
     */
    public function allowPrivate(bool | Closure $condition = true): static
    {
        $this->allowsPrivate = $condition;

        return $this;
    }

    /**
     * De sites waarop het keuzevenster standaard filtert: een site-id, een
     * lijst, of een closure die een van beide geeft. Zonder aanroep (of met
     * null) de sites van het record dat bewerkt wordt (`site_ids`), en
     * anders de actieve site. `false` zet het filter uit. De beheerder ziet
     * het filter in het venster en kan het daar uitzetten; een al gekozen
     * bestand van een andere site blijft gewoon staan.
     *
     * @param  array<int, string>|string|Closure|false|null  $sites
     */
    public function forSites(array | string | Closure | bool | null $sites): static
    {
        $this->forSites = $sites;

        return $this;
    }

    /**
     * Beperkt de externe bronnen (URL, stock, AI) in het keuzevenster van dit
     * veld: een lijst sleutels, of false voor geen enkele. Zonder aanroep
     * toont het venster elke bron die beschikbaar is. De server past dezelfde
     * beperking toe op elke aanroep van het venster.
     *
     * @param  array<int, string>|false|Closure|null  $sources
     */
    public function sources(array | bool | Closure | null $sources): static
    {
        $this->sources = $sources;

        return $this;
    }

    /**
     * @return array<int, string>|null  null is elke beschikbare bron
     */
    public function getSources(): ?array
    {
        $sources = $this->evaluate($this->sources);

        if ($sources === false) {
            return [];
        }

        return is_array($sources) ? array_values(array_filter($sources, fn ($key) => is_string($key) && $key !== '')) : null;
    }

    public function allowsPrivate(): bool
    {
        return (bool) $this->evaluate($this->allowsPrivate);
    }

    /**
     * @return array<int, string> leeg is "geen filter": één site, alle sites, of uitgezet
     */
    public function getSites(): array
    {
        if (! MediaSites::isMultiSite()) {
            return [];
        }

        $sites = $this->evaluate($this->forSites);

        if ($sites === false) {
            return [];
        }

        if ($sites === null || $sites === true) {
            $record = rescue(fn () => $this->getRecord(), null, false);
            $own = $record ? rescue(fn () => $record->getAttribute('site_ids'), null, false) : null;
            $sites = is_array($own) && $own !== [] ? $own : MediaSites::active();
        }

        return MediaSites::normalize(is_array($sites) ? $sites : [$sites]) ?? [];
    }

    /**
     * Of de gebruiker de mediabibliotheek mag zien. Zonder dat recht toont
     * het veld wat er gekozen is en verder niets: niet kiezen, niet
     * uploaden, niet weghalen. De waarde blijft bij opslaan precies wat hij was.
     */
    public function canBrowse(): bool
    {
        return MediaLibraryPage::mayManage('view');
    }

    public function driver(mixed ...$arguments): static
    {
        return $this;
    }

    public function showFileName(mixed ...$arguments): static
    {
        return $this;
    }

    public function buttonLabel(mixed ...$arguments): static
    {
        return $this;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function isReorderable(): bool
    {
        return (bool) $this->evaluate($this->isReorderable);
    }

    public function isDownloadable(): bool
    {
        return (bool) $this->evaluate($this->isDownloadable);
    }

    public function getMaxFiles(): ?int
    {
        $max = $this->evaluate($this->maxFiles);

        return is_numeric($max) && (int) $max > 0 ? (int) $max : null;
    }

    public function getMinFiles(): ?int
    {
        $min = $this->evaluate($this->minFiles);

        return is_numeric($min) && (int) $min > 0 ? (int) $min : null;
    }

    /**
     * @return array<int, string> in kleine letters, zonder onzin
     */
    public function getAcceptedFileTypes(): array
    {
        $types = $this->evaluate($this->acceptedFileTypes);
        $clean = [];

        foreach (is_iterable($types) ? $types : [] as $type) {
            $type = is_string($type) ? strtolower(trim($type)) : '';

            if ($type === '*/*') {
                return [];
            }

            if (preg_match('#^[a-z0-9][a-z0-9.+-]{0,60}/(\*|[a-z0-9][a-z0-9.+-]{0,60})$#', $type)) {
                $clean[$type] = $type;
            }
        }

        return array_values($clean);
    }

    /**
     * @return array{id: ?int, path: ?string} een pad blijft een pad tot iemand het venster opent
     */
    public function getDefaultFolder(): array
    {
        $folder = $this->evaluate($this->defaultFolder);

        if ($folder instanceof MediaFolder) {
            return ['id' => (int) $folder->getKey(), 'path' => null];
        }

        if (is_int($folder) || (is_string($folder) && ctype_digit($folder))) {
            return ['id' => (int) $folder > 0 ? (int) $folder : null, 'path' => null];
        }

        if (is_string($folder) && trim($folder, "/ \t\n") !== '') {
            return ['id' => null, 'path' => mb_substr(trim($folder), 0, 500)];
        }

        return ['id' => null, 'path' => null];
    }

    public static function mimeMatches(?string $mime, array $accepted): bool
    {
        if ($accepted === []) {
            return true;
        }

        $mime = strtolower((string) $mime);

        foreach ($accepted as $type) {
            if ($type === $mime || (str_ends_with($type, '/*') && str_starts_with($mime, substr($type, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    // ---------- Staat ----------

    /**
     * Elke vorm die er kan binnenkomen naar de staat van dit veld: een id of
     * null (enkel; een geplakt adres blijft tekst), of een lijst id's.
     *
     * @return int|string|array<int, int>|null
     */
    public function normalizeState(mixed $state): int | string | array | null
    {
        $helper = mediaHelper();

        if (! $this->isMultiple()) {
            // Tekst blijft precies zoals hij is, hoe lang ook: opslaan zonder
            // wijziging hoort bestaande inhoud niet te veranderen.
            return $helper->normalizeId($state);
        }

        $ids = [];

        foreach (is_array($state) ? $state : [$state] as $value) {
            $id = is_array($value) ? null : $helper->normalizeId($value);

            if (is_int($id)) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public function callAfterStateHydrated(): static
    {
        // Zonder het recht om te bladeren mag dit veld niets wijzigen: de
        // waarde zoals hij geladen is wordt onthouden (in de ondertekende memo
        // van de snapshot, want de staat zelf reist via de browser) en gaat
        // bij opslaan ongewijzigd terug.
        if (! $this->canBrowse()) {
            // Wordt het veld pas in een later verzoek gevuld (een blok dat
            // opengaat), dan telt wat er stond voor de updates van dat verzoek.
            $before = rescue(fn () => LockedFieldStates::beforeUpdates($this->getLivewire(), $this->getStatePath()), null, false);
            $this->rememberLockedState($before !== null ? $before['value'] : $this->getRawState());

            // De staat blijft hier precies wat hij was. Een Builder of
            // Repeater vult zijn velden twee keer (eerst op volgnummer, dan
            // op de sleutel van het item): na een normalisatie zou de tweede
            // keer een id als getal zien waar een tekst stond.
            MediaFieldItems::current()->announce($this->getIds());

            return parent::callAfterStateHydrated();
        }

        // Altijd terugschrijven: Filament leest een lege tekst als null,
        // terwijl hij in de staat van Livewire als lege tekst blijft staan.
        $this->rawState($state = $this->normalizeState($this->getRawState()));

        if (is_string($state)) {
            $this->rememberStoredText($state);
        }

        MediaFieldItems::current()->announce($this->getIds());

        return parent::callAfterStateHydrated();
    }

    /**
     * @return array<int, int> de gekozen id's, in volgorde
     */
    public function getIds(): array
    {
        $state = $this->normalizeState($this->effectiveRawState());

        if (is_array($state)) {
            return $state;
        }

        return is_int($state) ? [$state] : [];
    }

    /**
     * De tekst die in een enkel veld staat in plaats van een id: een geplakt
     * adres, of rommel uit oude inhoud.
     */
    public function getExternalValue(): ?string
    {
        $state = $this->isMultiple() ? null : $this->normalizeState($this->effectiveRawState());

        return is_string($state) ? $state : null;
    }

    /**
     * Alleen wat als http(s)-adres leest is een extern adres; al het andere
     * toont het veld als ongeldige waarde.
     */
    public static function isExternalUrl(?string $value): bool
    {
        if (! is_string($value) || ! preg_match('#^https?://#i', $value) || preg_match('/[\x00-\x20\x7F]/', $value)) {
            return false;
        }

        return filled(parse_url($value, PHP_URL_HOST));
    }

    /**
     * Onthoudt welke tekst er bij het laden in het veld stond. Die tekst mag
     * bij opslaan ongewijzigd terug; alleen een nieuwe tekst uit de browser
     * moet een geldig adres zijn. In de sessie, want de staat zelf reist via
     * de browser en is dus niet te vertrouwen.
     */
    protected function rememberStoredText(string $value): void
    {
        rescue(function () use ($value): void {
            $known = (array) session()->get(self::SESSION_KEY, []);
            $known[$this->storedTextKey()] = sha1($value);

            session()->put(self::SESSION_KEY, array_slice($known, -200, null, true));
        }, report: false);
    }

    protected function isStoredText(string $value): bool
    {
        return (bool) rescue(
            fn (): bool => hash_equals((string) (((array) session()->get(self::SESSION_KEY, []))[$this->storedTextKey()] ?? ''), sha1($value)),
            false,
            false,
        );
    }

    /**
     * De waarde van het laden van een alleen-lezen veld reist mee in de
     * ondertekende memo van de Livewire-snapshot (zie LockedFieldStates),
     * niet in de sessie.
     */
    protected function rememberLockedState(mixed $raw): void
    {
        rescue(fn () => LockedFieldStates::remember($this->getLivewire(), $this->getStatePath(), $raw), report: false);
    }

    /**
     * De waarde die een alleen-lezen veld houdt. Is er voor dit veld niets
     * bewaard, dan geldt wat de server zelf in de snapshot had: de staat van
     * vóór de updates van dit verzoek. Er komt dus altijd een waarde uit, en
     * nooit die van de browser.
     *
     * @return array{value: mixed}
     */
    protected function lockedState(): array
    {
        $livewire = $this->getLivewire();
        $path = $this->getStatePath();

        $known = rescue(fn () => LockedFieldStates::recall($livewire, $path), null, false);

        if ($known !== null) {
            return $known;
        }

        $known = rescue(fn () => LockedFieldStates::beforeUpdates($livewire, $path), null, false) ?? ['value' => $this->getRawState()];
        $this->rememberLockedState($known['value']);

        return $known;
    }

    /** Wat het veld toont en telt: voor een alleen-lezen veld de waarde van het laden, wat de browser ook stuurde. */
    protected function effectiveRawState(): mixed
    {
        return $this->canBrowse() ? $this->getRawState() : $this->lockedState()['value'];
    }

    protected function storedTextKey(): string
    {
        return sha1($this->getLivewire()->getId() . '|' . $this->getStatePath());
    }

    public function mutatesDehydratedState(): bool
    {
        return true;
    }

    /**
     * Naar de vorm waarin de inhoud het bewaart. Een id dat niet meer
     * bestaat valt uit een meervoudig veld weg, zoals bij de kiezer van het
     * pakket; in een enkel veld blijft het staan tot iemand iets anders kiest.
     */
    public function mutateDehydratedState(mixed $state): mixed
    {
        // Alleen-lezen: precies wat er bij het laden stond, wat de browser ook stuurde.
        if (! $this->canBrowse()) {
            return $this->lockedState()['value'];
        }

        $state = $this->normalizeState($state);

        if (is_array($state)) {
            $registry = MediaFieldItems::current();
            $registry->sweep($this);
            $registry->announce($state);

            // Alleen wat echt weg is valt af. Een item waarvan het bestand
            // even ontbreekt (nog niet gekoppeld, net vervangen) blijft staan.
            $state = array_values(array_map('strval', array_filter($state, fn (int $id): bool => $registry->exists($id))));
            $state = $state === [] ? null : $state;
        } elseif (is_int($state)) {
            $state = (string) $state;
        }

        return parent::mutateDehydratedState($state);
    }

    public function getStateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            // Zonder het recht om te bladeren telt alleen de waarde van het
            // laden; die komt er bij het opslaan voor in de plaats. Wat de
            // browser stuurde wordt dus genegeerd, niet afgekeurd: de rest
            // van het formulier hoort gewoon opgeslagen te worden.
            if (! $this->canBrowse()) {
                return;
            }

            $multiple = $this->isMultiple();

            foreach ($multiple && is_array($value) ? $value : [$value] as $entry) {
                $isId = is_int($entry) || (is_string($entry) && ctype_digit($entry));

                // Tekst die er bij het laden al stond mag blijven, wat het
                // ook is. Een nieuwe tekst moet een adres zijn, en niet te lang.
                $isExternal = ! $multiple && is_string($entry) && ! $isId
                    && ($this->isStoredText($entry) || (mb_strlen($entry) <= self::EXTERNAL_MAX && static::isExternalUrl($entry)));

                if ($entry !== null && $entry !== '' && ! $isId && ! $isExternal) {
                    $fail(__('Dit veld bevat een ongeldige waarde.'));

                    return;
                }
            }

            $ids = $this->getIds();

            if ($multiple && ($max = $this->getMaxFiles()) && count($ids) > $max) {
                $fail(__('Je kunt hier maximaal :max bestanden kiezen.', ['max' => $max]));

                return;
            }

            // Een leeg veld is de zaak van `required`, niet van het minimum.
            if ($multiple && $ids !== [] && ($min = $this->getMinFiles()) && count($ids) < $min) {
                $fail(__('Kies hier minstens :min bestanden.', ['min' => $min]));

                return;
            }

            $accepted = $this->getAcceptedFileTypes();

            if ($accepted === []) {
                return;
            }

            $registry = MediaFieldItems::current();
            $registry->sweep($this);
            $registry->announce($ids);

            foreach ($ids as $id) {
                // Wat niet meer bestaat houdt het opslaan niet tegen.
                if (($item = $registry->item($id)) && ! static::mimeMatches(static::mimeOf($item), $accepted)) {
                    $fail(__('Dit bestandstype is hier niet toegestaan'));

                    return;
                }
            }
        };
    }

    // ---------- Aanroepen vanuit de browser ----------

    /**
     * Zet de selectie uit het keuzevenster (of van een upload) op het veld.
     * Geeft alleen true terug als de staat echt gezet is; de browser leest
     * "geen true en geen false" als "dit veld bestaat niet meer".
     *
     * @param  array<int, mixed>  $ids
     */
    #[ExposedLivewireMethod]
    public function applySelection(array $ids, bool $append = false): bool
    {
        $this->authorizeMedia('view');

        if ($this->isDisabled()) {
            return $this->refuse(__('Dit veld kan niet worden gewijzigd.'));
        }

        $ids = $this->cleanIds($ids);

        if ($ids === []) {
            return $this->refuse(__('Selecteer eerst een of meer bestanden.'));
        }

        $registry = MediaFieldItems::current();
        $registry->announce($ids);
        $accepted = $this->getAcceptedFileTypes();

        foreach ($ids as $id) {
            $item = $registry->item($id);

            // Wat al in het veld stond mag in de prullenbak liggen; nieuw kiezen kan dat niet.
            if (! $item || ($item->trashed() && ! in_array($id, $this->getIds(), true))) {
                return $this->refuse(__('Dit bestand bestaat niet meer.'));
            }

            if (! static::mimeMatches(static::mimeOf($item), $accepted)) {
                return $this->refuse(__('Dit bestandstype is hier niet toegestaan'));
            }

            // Een privé-bestand alleen in een veld dat er bewust om vraagt. Wat al gekozen was blijft staan.
            if (! $this->allowsPrivate() && MediaVisibility::isPrivate($item) && ! in_array($id, $this->getIds(), true)) {
                return $this->refuse(__('Dit bestand staat in een privé-map en kan hier niet worden gekozen: de site kan het niet tonen.'));
            }
        }

        if (! $this->isMultiple()) {
            $this->commit($ids[0]);

            return true;
        }

        $state = $append ? array_values(array_unique([...$this->getIds(), ...$ids])) : $ids;

        if (($max = $this->getMaxFiles()) && count($state) > $max) {
            return $this->refuse(__('Je kunt hier maximaal :max bestanden kiezen.', ['max' => $max]));
        }

        $this->commit($state);

        return true;
    }

    #[ExposedLivewireMethod]
    public function removeItem(int $id): bool
    {
        $this->authorizeMedia('view');

        if ($this->isDisabled() || ! in_array($id, $current = $this->getIds(), true)) {
            return false;
        }

        $this->commit($this->isMultiple() ? array_values(array_diff($current, [$id])) : null);

        return true;
    }

    /**
     * Maakt het veld leeg, ook als er een geplakt adres in staat.
     */
    #[ExposedLivewireMethod]
    public function clearSelection(): bool
    {
        $this->authorizeMedia('view');

        if ($this->isDisabled()) {
            return false;
        }

        $this->commit($this->isMultiple() ? [] : null);

        return true;
    }

    /**
     * @param  array<int, mixed>  $ids  dezelfde id's als nu, in de nieuwe volgorde
     */
    #[ExposedLivewireMethod]
    public function reorder(array $ids): bool
    {
        $this->authorizeMedia('view');

        if ($this->isDisabled() || ! $this->isMultiple() || ! $this->isReorderable()) {
            return false;
        }

        $ids = $this->cleanIds($ids);
        $current = $this->getIds();
        $sorted = $ids;
        $currentSorted = $current;
        sort($sorted);
        sort($currentSorted);

        if ($sorted !== $currentSorted) {
            return false;
        }

        if ($ids !== $current) {
            $this->commit($ids);
        }

        return true;
    }

    /**
     * De taal waarin dit veld de alt-tekst toont en bewerkt: de taal van het
     * formulier als de pagina er een bijhoudt (de taalwissel van een
     * vertaalbaar record), anders de eerste taal van de installatie. Nooit
     * de taal van de applicatie: dat is in het paneel die van de beheerder.
     */
    public function getTextLocale(): string
    {
        $locale = rescue(function () {
            $livewire = $this->getLivewire();

            return property_exists($livewire, 'activeLocale') ? $livewire->activeLocale : null;
        }, null, false);

        // De eigenschap komt uit de browser: alleen een taal van de installatie telt.
        return is_string($locale) && MediaTexts::isLocale($locale) ? $locale : MediaTexts::firstLocale();
    }

    #[ExposedLivewireMethod]
    public function updateAlt(int $id, ?string $alt = null): bool
    {
        $this->authorizeMedia('edit');

        // Alleen voor wat in dit veld gekozen is: het veld is geen achterdeur
        // naar de tekst van elk bestand in de bibliotheek.
        if ($this->isDisabled() || ! in_array($id, $this->getIds(), true)) {
            return false;
        }

        $library = app(MediaLibrary::class);
        $item = $library->find($id, withTrashed: true);

        if (! $item) {
            return $this->refuse(__('Dit bestand bestaat niet meer.'));
        }

        if (! in_array(MediaItemPayload::typeOf(static::mimeOf($item)), ['image', 'svg'], true)) {
            return $this->refuse(__('Alleen een afbeelding heeft een alt-tekst.'));
        }

        $alt = trim((string) $alt);

        if (mb_strlen($alt) > self::ALT_MAX) {
            return $this->refuse(__('De tekst mag maximaal :max tekens zijn.', ['max' => self::ALT_MAX]));
        }

        abort_unless($library->canUpdate(auth()->user(), $item), 403);

        $library->setText($item, 'alt', $this->getTextLocale(), $alt);

        MediaFieldItems::current()->forget($id);
        $this->partiallyRender();

        return true;
    }

    /**
     * Haalt een gekozen bestand terug uit de prullenbak.
     */
    #[ExposedLivewireMethod]
    public function restoreItem(int $id): bool
    {
        $this->authorizeMedia('delete');

        // Alleen voor wat in dit veld gekozen is: het veld is geen achterdeur
        // naar de hele prullenbak.
        if ($this->isDisabled() || ! in_array($id, $this->getIds(), true)) {
            return false;
        }

        $library = app(MediaLibrary::class);
        $item = $library->find($id, withTrashed: true);

        if (! $item || ! $item->trashed()) {
            return $this->refuse(__('Dit bestand ligt niet in de prullenbak.'));
        }

        abort_unless($library->canDelete(auth()->user(), $item), 403);

        $result = app(\Dashed\DashedFiles\Classes\Browse\MediaBulk::class)->restoreItems([$id]);

        Notification::make()
            ->title($result['moved_to_root'] > 0 ? __('Teruggezet in de hoofdmap: de map van dit bestand bestaat niet meer.') : __('Teruggezet'))
            ->success()
            ->send();

        MediaFieldItems::current()->forget($id);
        $this->partiallyRender();

        return true;
    }

    protected function authorizeMedia(string $ability): void
    {
        abort_unless(MediaLibrary::usesOwnEngine() && MediaLibraryPage::mayManage($ability), 403);
    }

    /**
     * @param  int|array<int, int>|null  $state
     */
    protected function commit(int | array | null $state): void
    {
        $this->state($state);
        $this->callAfterStateUpdated();

        // Niemand luistert mee: dan volstaat het om dit ene veld opnieuw te
        // tekenen. Met een haak of live() kan er elders iets van afhangen.
        if (! $this->hasStateListeners()) {
            $this->partiallyRender();
        }
    }

    protected function hasStateListeners(): bool
    {
        try {
            for ($component = $this; $component !== null; $component = $component->getContainer()->getParentComponent()) {
                if ($component->afterStateUpdated !== [] || $component->isLive()) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return true;
        }
    }

    protected function refuse(string $message): bool
    {
        Notification::make()->title($message)->danger()->send();

        // Er is niets veranderd: de pagina hoeft niet opnieuw getekend.
        rescue(fn () => $this->skipRender(), report: false);

        return false;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    protected function cleanIds(array $ids): array
    {
        $clean = [];

        foreach (array_slice($ids, 0, self::MAX_SELECTION) as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id) && strlen($id) <= 18)) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }

        return array_values($clean);
    }

    protected static function mimeOf(MediaItem $item): ?string
    {
        return $item->mime_type ?? $item->file()?->mime_type;
    }

    // ---------- Weergave ----------

    /**
     * De tegels van het veld. Een id dat niet meer bestaat komt mee als
     * `missing`, zodat de beheerder ziet dat er iets stond en het kan weghalen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getViewItems(): array
    {
        $ids = $this->getIds();

        if ($ids === []) {
            return [];
        }

        $registry = MediaFieldItems::current();
        $registry->sweep($this);
        $registry->announce($ids);

        $manageUrl = MediaLibraryPage::mayManage('edit') ? rescue(fn () => MediaLibraryPage::getUrl(), null, false) : null;

        $items = [];
        $multilingual = MediaTexts::isMultilingual();
        $locale = $this->getTextLocale();

        foreach ($ids as $id) {
            $payload = $registry->payload($id);

            $items[] = $payload === null
                ? ['id' => $id, 'missing' => true]
                : [
                    'id' => $id,
                    'missing' => false,
                    'name' => (string) ($payload['name'] !== '' ? $payload['name'] : $payload['file_name']),
                    'file_name' => (string) $payload['file_name'],
                    'type' => $payload['type'],
                    'thumb' => $payload['thumb'],
                    'thumb_small' => $payload['thumb_small'] ?? null,
                    'url' => $payload['url'],
                    // De alt-tekst in de taal van het formulier, zonder terugval: wat hier staat is wat er bewerkt wordt.
                    'alt' => $alt = ($multilingual ? ($registry->item($id)?->translations('alt')[$locale] ?? null) : $payload['alt']),
                    'needsAlt' => in_array($payload['type'], ['image', 'svg'], true) && blank($alt),
                    // Alleen bij meer dan één taal: dan staat bij het invoerveld welke taal het is.
                    'altLocale' => $multilingual ? $locale : null,
                    'hasAlt' => in_array($payload['type'], ['image', 'svg'], true),
                    'trashed' => (bool) ($payload['trashed'] ?? false),
                    'private' => (bool) ($payload['private'] ?? false),
                    // Vervangen van het bestand zelf gebeurt op één plek: de beheerpagina, met dit item open.
                    'manageUrl' => $manageUrl === null ? null : $manageUrl . (str_contains($manageUrl, '?') ? '&' : '?') . 'item=' . $id,
                ];
        }

        return $items;
    }

    /**
     * Wat het script van het veld nodig heeft, als base64: een JSON-tekst
     * hoort niet rauw in een attribuut.
     */
    public function getBrowserConfig(): string
    {
        $folder = $this->getDefaultFolder();
        $restrictions = [
            'multiple' => $this->isMultiple(),
            'max' => $this->getMaxFiles(),
            'accept' => $this->getAcceptedFileTypes(),
            'allowPrivate' => $this->allowsPrivate(),
            'sites' => $this->getSites(),
            'sources' => $this->getSources(),
        ];

        return base64_encode((string) json_encode([
            'key' => $this->getKey(),
            // Het id van de pagina waar dit veld op staat: een veld in een
            // modal die naar de body is verplaatst vindt dat niet via de DOM.
            'livewireId' => rescue(fn () => $this->getLivewire()->getId(), null, false),
            'statePath' => $this->getStatePath(),
            'name' => $this->getName(),
            'multiple' => $this->isMultiple(),
            'max' => $this->getMaxFiles(),
            'accept' => $this->getAcceptedFileTypes(),
            'folder' => $folder['id'],
            'folderPath' => $folder['path'],
            'selected' => $this->getIds(),
            'canUpload' => MediaLibraryPage::mayManage('upload'),
            'allowPrivate' => $this->allowsPrivate(),
            'sites' => $this->getSites(),
            'sources' => $this->getSources(),
            // Het venster vertrouwt bronnen, privé en sites alleen met deze handtekening.
            'signature' => \Dashed\DashedFiles\Classes\Access\PickerSignature::sign($restrictions),
            'labels' => [
                'gone' => __('Dit veld is niet meer open; open het blok en kies opnieuw.'),
                'notAccepted' => __('Dit bestandstype is hier niet toegestaan'),
                'onlyOne' => __('Dit veld neemt één bestand; alleen het eerste is geüpload'),
                'tooMany' => __('Je kunt hier maximaal :max bestanden kiezen.'),
                'uploading' => __('Bezig met uploaden (:percent%)'),
                'failed' => __('Uploaden mislukt'),
                'tooLarge' => __('Te groot: maximaal :max MB per bestand.'),
                'pastedName' => __('Geplakte afbeelding'),
                'orphaned' => __('Het bestand staat in de mediabibliotheek, maar is niet aan dit veld gekoppeld.'),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
