<?php

namespace Dashed\DashedFiles\Livewire;

use Throwable;
use Livewire\Component;
use Livewire\Attributes\Locked;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Renderless;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Livewire\Concerns\BrowsesMedia;
use Dashed\DashedFiles\Livewire\Concerns\UsesMediaSources;
use Dashed\DashedFiles\Livewire\Concerns\ReceivesMediaUploads;

/**
 * Het keuzevenster van de mediabibliotheek, één per paneelpagina. Dicht is
 * het een lege schil zonder query's; de browser opent het met openPicker()
 * en krijgt dan de instellingen voor de lijst in kies-modus terug. De
 * markup van die lijst (ruim 90 kB) wordt niet bij elke opening opnieuw
 * gerenderd: openPicker() levert hem de eerste keer als tekst mee, en de
 * browser bewaart hem en zet er per opening alleen de instellingen in.
 * Wat er gekozen wordt gaat niet via dit component terug maar rechtstreeks
 * naar het veld (MediaField::applySelection), dat alles opnieuw controleert.
 */
class MediaPickerModal extends Component
{
    use BrowsesMedia {
        loadItems as protected browseLoadItems;
        selectAll as protected browseSelectAll;
    }
    use ReceivesMediaUploads;
    use UsesMediaSources;

    public const COMPONENT = 'dashed-files.media-picker-modal';

    public const MODAL_ID = 'dashed-media-picker';

    // Vergrendeld: de server vertrouwt deze drie (het typefilter hangt aan
    // $pick), dus de browser mag ze alleen via openPicker() en closePicker() zetten.
    #[Locked]
    public bool $open = false;

    /** @var array<string, mixed> wat het veld vroeg, opgeschoond */
    #[Locked]
    public array $pick = [];

    #[Locked]
    public int $opened = 0;

    /**
     * Zonder Livewire-render: de staat (open, pick) gaat mee in het antwoord
     * en de lijst komt als data terug, niet als nieuwe HTML van het hele venster.
     *
     * @param  array<string, mixed>  $request  accept, multiple, max, folder, folderPath, selected, remember, sources, allowPrivate, sites, signature, template
     * @return array{ok: bool, config: array<string, mixed>, html?: string}
     */
    #[Renderless]
    public function openPicker(array $request = []): array
    {
        $this->authorizeMedia('view');

        $accept = [];

        foreach (array_slice(is_array($request['accept'] ?? null) ? $request['accept'] : [], 0, 25) as $type) {
            $type = is_string($type) ? strtolower(trim($type)) : '';

            if (preg_match('#^[a-z0-9][a-z0-9.+-]{0,60}/(\*|[a-z0-9][a-z0-9.+-]{0,60})$#', $type)) {
                $accept[] = $type;
            }
        }

        $max = $request['max'] ?? null;
        $remember = $request['remember'] ?? null;
        // Fase 11: null is elke beschikbare bron, een lijst beperkt, false of een lege lijst is geen enkele.
        $sources = $request['sources'] ?? null;
        $sources = $sources === false ? [] : (is_array($sources)
            ? array_values(array_unique(array_filter(array_slice($sources, 0, 20), fn ($key) => is_string($key) && preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $key))))
            : null);
        $startSource = $request['source'] ?? null;

        // Bronnen, privé en sites zijn instellingen van het veld en komen
        // alleen met de handtekening van de server. Zonder geldige: de
        // strengste stand, wat de browser er ook in zet.
        $signed = \Dashed\DashedFiles\Classes\Access\PickerSignature::verify($request);

        if (! $signed) {
            $sources = [];
            $request['allowPrivate'] = false;
            $request['sites'] = [];
        }

        $this->pick = [
            'accept' => array_values(array_unique($accept)),
            'multiple' => (bool) ($request['multiple'] ?? false),
            'max' => is_numeric($max) && (int) $max > 0 ? min((int) $max, self::MEDIA_MAX_IDS) : null,
            'folder' => $this->pickFolder($request),
            'selected' => array_slice($this->mediaIds(is_array($request['selected'] ?? null) ? $request['selected'] : []), 0, 500),
            'remember' => is_string($remember) ? mb_substr($remember, 0, 300) : null,
            'upload' => static::mayManage('upload'),
            'sources' => $sources,
            // Het tabblad waarop het venster opent (de knop "Genereer met AI" op een veld).
            'source' => is_string($startSource) && preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $startSource) ? $startSource : null,
            // Privé-mappen en -bestanden alleen voor een veld dat er bewust om vraagt (allowPrivate()).
            // Wat de browser hier stuurt is geen recht: wie dit venster opent mag met view_media
            // elk privé-bestand al zien, en het veld toetst elke keuze zelf opnieuw.
            'allowPrivate' => (bool) ($request['allowPrivate'] ?? false),
            // De sites van het record dat bewerkt wordt: het venster filtert daar standaard op, zichtbaar en uit te zetten.
            'sites' => \Dashed\DashedFiles\Classes\Access\MediaSites::isMultiSite()
                ? (\Dashed\DashedFiles\Classes\Access\MediaSites::normalize(is_array($request['sites'] ?? null) ? array_slice($request['sites'], 0, 100) : null) ?? [])
                : [],
        ];

        $this->open = true;
        // Elke opening is een nieuw venster: de browser bouwt de lijst met
        // deze instellingen opnieuw op en houdt niets van het vorige veld.
        $this->opened++;

        $result = ['ok' => true, 'config' => $this->pickerBrowserConfig()];

        // De markup is voor iedereen gelijk (de instellingen zitten er niet in):
        // alleen de eerste opening na het laden van de pagina vraagt erom.
        if (! ($request['template'] ?? false)) {
            $result['html'] = view('dashed-files::media.browser', ['mediaConfig' => []])->render();
        }

        return $result;
    }

    #[Renderless]
    public function closePicker(): void
    {
        $this->open = false;
        $this->pick = [];
    }

    /**
     * De map van een pad ("producten/2026"), voor een veld met een
     * standaardmap op naam. Maakt hem aan als hij er nog niet is.
     */
    #[Renderless]
    public function folderForPath(string $path): ?int
    {
        $this->authorizeMedia('upload');

        return $this->folderIdOf($path);
    }

    /**
     * De lijst met het typefilter van het veld erbovenop: wat de browser
     * ook stuurt, het venster toont alleen wat het veld aanneemt.
     */
    #[Renderless]
    public function loadItems(array $filters = [], int $page = 1): array
    {
        return $this->browseLoadItems($this->withPickFilter($filters), $page);
    }

    #[Renderless]
    public function selectAll(array $filters = []): array
    {
        return $this->browseSelectAll($this->withPickFilter($filters));
    }

    // Het venster staat op elke paneelpagina. Daarom is het geen beheer: de
    // ene poort in BrowsesMedia::authorizeMedia() laat hier alleen bladeren,
    // zoeken, kiezen en uploaden door, en de lijst kent geen Ongebruikt,
    // Dubbel of Prullenbak.
    protected function mediaMode(): string
    {
        return 'pick';
    }

    protected function mediaShowsPrivate(): bool
    {
        return (bool) ($this->pick['allowPrivate'] ?? false);
    }

    /**
     * De bronnen die het veld toelaat. Een dicht venster heeft er geen.
     *
     * @return array<int, string>|null
     */
    protected function mediaSourceFilter(): ?array
    {
        if (! $this->open) {
            return [];
        }

        $sources = $this->pick['sources'] ?? null;

        return is_array($sources) ? $sources : null;
    }

    public function render(): View
    {
        return view('dashed-files::media.picker-modal');
    }

    /**
     * @return array<string, mixed>
     */
    public function pickerBrowserConfig(): array
    {
        return array_merge($this->mediaBrowserConfig('pick'), [
            'pick' => $this->pick,
            'pickLabels' => [
                'count' => __(':count geselecteerd'),
                'countMax' => __(':count geselecteerd (maximaal :max)'),
                'maxReached' => __('Je kunt hier maximaal :max bestanden kiezen.'),
                'notAccepted' => __('Dit bestandstype is hier niet toegestaan'),
                'chosen' => __('Al gekozen'),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function withPickFilter(array $filters): array
    {
        if ($this->open && ($this->pick['accept'] ?? []) !== []) {
            $filters['mime'] = $this->pick['accept'];
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $request
     */
    protected function pickFolder(array $request): ?int
    {
        $folder = $request['folder'] ?? null;

        if (is_int($folder) || (is_string($folder) && ctype_digit($folder))) {
            return (int) $folder > 0 && app(MediaLibrary::class)->folder((int) $folder) ? (int) $folder : null;
        }

        $path = $request['folderPath'] ?? null;

        // Een pad opzoeken kan een map aanmaken; dat mag alleen wie ook mag uploaden.
        return is_string($path) && static::mayManage('upload') ? $this->folderIdOf($path) : null;
    }

    protected function folderIdOf(string $path): ?int
    {
        $path = mb_substr(trim($path), 0, 500);

        if (trim($path, "/ \t\n") === '' || count(explode('/', $path)) > 10) {
            return null;
        }

        try {
            return app(MediaLibrary::class)->folderIdForPath($path);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
