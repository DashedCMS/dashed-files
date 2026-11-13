<?php

namespace Dashed\DashedFiles\Livewire\Concerns;

use Closure;
use Throwable;
use ZipArchive;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Renderless;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedFiles\Models\MediaItem;
use Filament\Notifications\Notification;
use Dashed\DashedFiles\Classes\MediaTexts;
use Illuminate\Support\Facades\RateLimiter;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Illuminate\Validation\ValidationException;
use Dashed\DashedFiles\Classes\Browse\MediaBulk;
use Dashed\DashedFiles\Classes\Access\MediaSites;
use Dashed\DashedFiles\Classes\Usage\MediaUsages;
use Dashed\DashedFiles\Classes\Access\MediaRights;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\MediaActionRegistry;
use Dashed\DashedFiles\Classes\Ingest\IncomingMedia;
use Dashed\DashedFiles\Classes\Browse\MediaItemQuery;
use Dashed\DashedFiles\Classes\Access\MediaVisibility;
use Dashed\DashedFiles\Classes\Browse\MediaFolderTree;
use Dashed\DashedFiles\Classes\Browse\MediaItemPayload;
use Dashed\DashedFiles\Classes\Usage\MediaUsageIndexer;
use Dashed\DashedFiles\Http\Controllers\MediaZipController;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;

/**
 * De Livewire-kant van de mediabibliotheek: lijst, boom, detail en
 * mutaties. Alles geeft data terug en rendert niets; de browser houdt de
 * lijst zelf bij. Gedeeld door de beheerpagina en een later keuzevenster.
 */
trait BrowsesMedia
{
    /** @var array<int, string> de soorten die authorizeMedia() onderscheidt */
    public const MEDIA_ABILITIES = ['view', 'upload', 'edit', 'delete', 'folders', 'manage', 'purge'];

    /** @var array<int, string> wat een keuzevenster mag: bladeren, zoeken, kiezen en uploaden */
    public const MEDIA_PICK_ABILITIES = ['view', 'upload'];

    public const MEDIA_PER_PAGE = 60;

    public const MEDIA_MAX_IDS = 5000;

    public const MEDIA_TEXT_MAX = 255;

    public const MEDIA_ZIP_MAX_FILES = 200;

    public const MEDIA_ZIP_MAX_BYTES = 500 * 1024 * 1024;

    public const MEDIA_ZIP_PER_MINUTE = 3;

    public const MEDIA_USAGES_SHOWN = 100;

    /**
     * Of de ingelogde paneelgebruiker dit mag. De rechten zelf staan op één
     * plek: MediaLibrary::allows() (Access\MediaRights). Zonder gebruiker,
     * zonder paneeltoegang of bij een onbekende handeling: nee.
     */
    public static function mayManage(string $ability = 'view'): bool
    {
        if (! in_array($ability, self::MEDIA_ABILITIES, true)) {
            return false;
        }

        return MediaLibrary::allows($ability, MediaRights::user());
    }

    /**
     * De ene poort voor elke publieke methode. Een keuzevenster staat op
     * elke paneelpagina en mag alleen bladeren, zoeken, kiezen en uploaden:
     * alles wat beheer is weigert de server daar hard, wat de browser ook stuurt.
     */
    protected function authorizeMedia(string $ability): void
    {
        // Ook de motor: een pagina of venster dat nog open staat nadat er is
        // teruggeschakeld, hoort niet door te gaan met wijzigen.
        abort_unless(
            in_array($ability, $this->mediaAllowedAbilities(), true) && MediaLibrary::usesOwnEngine() && static::mayManage($ability),
            403,
        );
    }

    /**
     * @return array<int, string>
     */
    protected function mediaAllowedAbilities(): array
    {
        return $this->mediaMode() === 'pick' ? self::MEDIA_PICK_ABILITIES : self::MEDIA_ABILITIES;
    }

    /** `manage` voor de beheerpagina, `pick` voor een keuzevenster. */
    protected function mediaMode(): string
    {
        return 'manage';
    }

    #[Renderless]
    public function loadItems(array $filters = [], int $page = 1): array
    {
        $this->authorizeMedia('view');

        // Het totaal alleen op de eerste pagina: doorscrollen hoort geen COUNT te kosten.
        return MediaItemQuery::make($this->mediaFilters($filters), $this->mediaUserId())
            ->withTotal($page <= 1)
            ->page($page, self::MEDIA_PER_PAGE)
            ->toArray();
    }

    #[Renderless]
    public function loadTree(): array
    {
        $this->authorizeMedia('view');

        return $this->mediaTree();
    }

    #[Renderless]
    public function loadItem(int $id): array
    {
        $this->authorizeMedia('view');

        // Ook uit de prullenbak, maar alleen op de beheerpagina: daar is het
        // detailpaneel de plek om terug te zetten.
        $item = MediaItem::query()
            ->when($this->mediaManagesTrash() && BrowseSchema::hasTrash(), fn ($query) => $query->withTrashed())
            ->with(BrowseSchema::hasRelations() ? ['media', 'tags'] : ['media'])
            ->find($id);

        // Een keuzevenster zonder allowPrivate() kent de privé-bestanden niet.
        if (! $item || (! $this->mediaShowsPrivate() && MediaVisibility::isPrivate($item))) {
            return ['ok' => false, 'error' => __('Dit bestand bestaat niet meer.')];
        }

        return ['ok' => true, 'item' => $this->mediaDetail($item)];
    }

    /**
     * Waar een bestand gebruikt wordt, voor het detailpaneel en de
     * verwijderbevestiging.
     */
    #[Renderless]
    public function loadUsages(int $id): array
    {
        $this->authorizeMedia('manage');

        $library = app(MediaLibrary::class);

        return [
            'ok' => true,
            'ready' => app(MediaUsageIndexer::class)->isReady(),
            'count' => (int) ($library->usageCount([$id])[$id] ?? 0),
            'usages' => $library->usagesOf($id, self::MEDIA_USAGES_SHOWN),
        ];
    }

    /**
     * Wat de bevestiging voor verwijderen moet zeggen: hoeveel bestanden, en
     * hoeveel daarvan volgens de index nog in gebruik zijn.
     */
    #[Renderless]
    public function describeDelete(array $ids): array
    {
        $this->authorizeMedia('delete');

        $ids = $this->mediaIds($ids);
        $ready = app(MediaUsageIndexer::class)->isReady();

        return [
            'ok' => true,
            'count' => count($ids),
            'ready' => $ready,
            'inUse' => $ready ? app(MediaUsages::class)->usedAmong($ids) : null,
            // Bij één bestand ook de plekken zelf.
            'places' => $ready && count($ids) === 1 ? (int) (app(MediaLibrary::class)->usageCount($ids)[$ids[0]] ?? 0) : null,
            'usages' => $ready && count($ids) === 1 ? app(MediaLibrary::class)->usagesOf($ids[0], 10) : [],
            'days' => MediaItemQuery::trashDays(),
        ];
    }

    /**
     * Wat de bevestiging voor definitief verwijderen moet zeggen: hoeveel
     * van de selectie de site nog toont of de index nog kent.
     */
    #[Renderless]
    public function describePurge(array $ids): array
    {
        $this->authorizeMedia('purge');

        $ids = $this->mediaIds($ids);
        $inUse = [];

        if ($ids !== [] && BrowseSchema::hasTrash()) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $used = app(MediaUsages::class)->counts($chunk);
                $served = BrowseSchema::hasServed()
                    ? MediaItem::withoutGlobalScopes()->toBase()->whereIn('id', $chunk)->whereNotNull('trashed_served_at')->pluck('id')->all()
                    : [];

                foreach ([...array_keys($used), ...$served] as $id) {
                    $inUse[(int) $id] = true;
                }
            }
        }

        return ['ok' => true, 'count' => count($ids), 'inUse' => count($inUse)];
    }

    #[Renderless]
    public function deleteItems(array $ids): array
    {
        $this->authorizeMedia('delete');

        return $this->mediaGuarded(function () use ($ids) {
            $ids = $this->mediaIds($ids);

            $this->mediaAuthorizeEach($ids, 'delete');

            return [
                'deleted' => app(MediaBulk::class)->deleteItems($ids, $this->mediaUserId()),
                'tree' => $this->mediaTree(),
            ];
        });
    }

    #[Renderless]
    public function restoreItems(array $ids): array
    {
        $this->authorizeMedia('delete');

        return $this->mediaGuarded(function () use ($ids) {
            $ids = $this->mediaIds($ids);

            $this->mediaAuthorizeEach($ids, 'delete', trashed: true);

            $result = app(MediaBulk::class)->restoreItems($ids);

            return [
                'restored' => $result['restored'],
                'movedToRoot' => $result['moved_to_root'],
                'message' => $result['moved_to_root'] > 0
                    ? __('Van :count teruggezette bestanden bestaat de map niet meer: die staan nu in de hoofdmap.', ['count' => $result['moved_to_root']])
                    : null,
                'tree' => $this->mediaTree(),
            ];
        });
    }

    /**
     * Definitief verwijderen, alleen vanuit de prullenbak.
     */
    #[Renderless]
    public function purgeItems(array $ids): array
    {
        $this->authorizeMedia('purge');

        return $this->mediaGuarded(function () use ($ids) {
            $ids = $this->mediaIds($ids);

            $this->mediaAuthorizeEach($ids, 'delete', trashed: true);

            return [
                'purged' => app(MediaBulk::class)->purgeItems($ids),
                'tree' => $this->mediaTree(),
            ];
        });
    }

    #[Renderless]
    public function loadTrashedFolders(): array
    {
        $this->authorizeMedia('manage');

        return ['ok' => true, 'folders' => app(MediaFolderTree::class)->trashed(), 'days' => MediaItemQuery::trashDays()];
    }

    #[Renderless]
    public function restoreFolder(int $id): array
    {
        $this->authorizeMedia('folders');
        // Een map terugzetten zet ook elk bestand terug dat ermee is weggegooid.
        $this->authorizeMedia('delete');

        return $this->mediaGuarded(function () use ($id) {
            $result = app(MediaFolderTree::class)->restore($id);

            return ['id' => $id, 'folders' => $result['folders'], 'items' => $result['items'], 'tree' => $this->mediaTree()];
        });
    }

    /**
     * Voegt dubbele bestanden samen: $keepId blijft, de rest gaat naar de
     * prullenbak nadat elke verwijzing is omgezet.
     */
    #[Renderless]
    public function mergeDuplicates(int $keepId, array $ids): array
    {
        // Verwijzingen herschrijven en bestanden weggooien: hetzelfde recht als definitief verwijderen.
        $this->authorizeMedia('purge');
        // En wat het doet: het ene bestand aanpassen, de andere weggooien.
        $this->authorizeMedia('edit');
        $this->authorizeMedia('delete');

        return $this->mediaGuarded(function () use ($keepId, $ids) {
            $library = app(MediaLibrary::class);
            $keep = $this->mediaItemOrFail($keepId);
            $others = array_values(array_diff($this->mediaIds($ids), [$keepId]));

            abort_unless($library->canUpdate(auth()->user(), $keep), 403);
            $this->mediaAuthorizeEach($others, 'delete');

            $result = $library->mergeDuplicates($keep, $others, $this->mediaUserId());

            if ($result['queued']) {
                $message = __('Dit zijn veel plekken tegelijk: het samenvoegen loopt op de achtergrond. Je krijgt een melding als het klaar is.');
            } else {
                $message = __('Samengevoegd: :count verwijzingen omgezet.', ['count' => $result['converted']]);

                if ($result['kept_out'] !== []) {
                    $message .= ' ' . __(':count bestanden zijn niet weggegooid omdat een verwijzing niet zeker om te zetten was.', ['count' => count($result['kept_out'])]);
                }
            }

            return [
                'queued' => $result['queued'],
                'batch' => $result['batch'],
                'converted' => $result['converted'],
                'failed' => $result['failed'],
                'trashed' => $result['trashed'],
                'keptOut' => $result['kept_out'],
                'message' => $message,
                'tree' => $this->mediaTree(),
            ];
        });
    }

    /**
     * Draait een samenvoeging terug: de verwijzingen wijzen weer naar het
     * samengevoegde bestand, dat uit de prullenbak komt.
     */
    #[Renderless]
    public function undoMerge(string $batch): array
    {
        $this->authorizeMedia('purge');

        return $this->mediaGuarded(function () use ($batch) {
            $result = app(MediaLibrary::class)->undoMerge($batch);

            $message = __('Samenvoegen ongedaan gemaakt: :count verwijzingen teruggezet.', ['count' => $result['restored']]);

            if ($result['failed'] > 0 || $result['missing'] !== []) {
                $message .= ' ' . __(':count konden niet terug, omdat de plek intussen is gewijzigd of het bestand definitief weg is.', ['count' => $result['failed'] + count($result['missing'])]);
            }

            return [
                'restored' => $result['restored'],
                'failed' => $result['failed'],
                'items' => $result['items'],
                'missing' => $result['missing'],
                'message' => $message,
                'tree' => $this->mediaTree(),
            ];
        });
    }

    #[Renderless]
    public function allTags(?string $search = null): array
    {
        $this->authorizeMedia('view');

        return app(MediaLibrary::class)->allTags($search === null ? null : mb_substr($search, 0, 100), 50);
    }

    /**
     * Eén veld in één taal. Zonder taal de eerste taal van de installatie,
     * nooit die van de beheerder.
     */
    #[Renderless]
    public function saveText(int $id, string $field, ?string $value = null, ?string $locale = null): array
    {
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($id, $field, $value, $locale) {
            $item = $this->mediaItemOrFail($id);
            $library = app(MediaLibrary::class);
            $value = trim((string) $value);
            $locale ??= MediaTexts::firstLocale();

            abort_unless($library->canUpdate(auth()->user(), $item), 403);

            if (! MediaTexts::isLocale($locale)) {
                throw ValidationException::withMessages(['locale' => __('Deze taal bestaat niet op deze site.')]);
            }

            if ($field === 'title') {
                app(MediaBulk::class)->rename($item, $value, $locale);
            } else {
                $name = in_array($field, ['alt', 'alt_text', 'caption'], true) ? MediaTexts::field($field) : null;

                if ($name === null) {
                    throw ValidationException::withMessages(['field' => __('Dit veld kan niet worden bewerkt.')]);
                }

                if ($name === 'alt' && ! in_array(MediaItemPayload::typeOf($this->mediaMime($item)), ['image', 'svg'], true)) {
                    throw ValidationException::withMessages(['field' => __('Alleen een afbeelding heeft een alt-tekst.')]);
                }

                if (mb_strlen($value) > self::MEDIA_TEXT_MAX) {
                    throw ValidationException::withMessages(['field' => __('De tekst mag maximaal :max tekens zijn.', ['max' => self::MEDIA_TEXT_MAX])]);
                }

                $library->setText($item, $name, $locale, $value);
            }

            $item->refresh();

            return [
                'id' => (int) $item->getKey(),
                'name' => $item->display_name,
                'title' => $item->title,
                'alt' => filled($item->alt_text) ? (string) $item->alt_text : null,
                'caption' => filled($item->caption) ? (string) $item->caption : null,
                'texts' => $this->mediaTexts($item),
                'alt_missing' => in_array(MediaItemPayload::typeOf($this->mediaMime($item)), ['image', 'svg'], true) ? $item->missingAltLocales() : [],
            ];
        });
    }

    /**
     * Titel, alt-tekst en bijschrift per taal, voor het detailpaneel.
     *
     * @return array{title: array<string, string>, alt: array<string, string>, caption: array<string, string>}
     */
    protected function mediaTexts(MediaItem $item): array
    {
        // Een leeg veld als object en niet als lijst: de browser leest het per taal.
        return array_map(fn (string $field) => (object) $item->translations($field), ['title' => 'title', 'alt' => 'alt', 'caption' => 'caption']);
    }

    /**
     * "Opnieuw proberen" bij een afbeelding waar de AI na drie pogingen geen
     * alt-tekst van maakte: zet de teller terug en start de taak opnieuw.
     */
    #[Renderless]
    public function retryAltText(int $id): array
    {
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($id) {
            $item = $this->mediaItemOrFail($id);
            $library = app(MediaLibrary::class);

            abort_unless($library->canUpdate(auth()->user(), $item), 403);

            $action = MediaActionRegistry::find(MediaActionRegistry::ITEM, 'generate-alt-text');

            // Dezelfde toets als de knop zelf: geen pdf, geen chatbijlage, en alleen met een AI-pakket.
            if (! $action || ($action['visible'] && ! ($action['visible'])($item)) || $item->missingAltLocales() === []) {
                throw ValidationException::withMessages(['action' => __('Deze actie kan niet op dit bestand.')]);
            }

            $library->resetAltTries([(int) $item->getKey()]);
            $item->refresh();

            $message = ($action['handle'])($item, []);

            return ['message' => is_string($message) ? $message : null, 'ai_alt_failed' => false];
        });
    }

    #[Renderless]
    public function setTags(array $ids, array $names, string $mode = 'replace'): array
    {
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($ids, $names, $mode) {
            if (! in_array($mode, ['replace', 'add', 'remove'], true)) {
                throw ValidationException::withMessages(['mode' => __('Onbekende bewerking.')]);
            }

            $ids = $this->mediaIds($ids);
            $names = array_slice(array_filter($names, fn ($name) => is_string($name) || is_numeric($name)), 0, 100);

            abort_unless(app(MediaLibrary::class)->canUpdateAll(auth()->user(), $ids), 403);

            app(MediaLibrary::class)->setTags($ids, $names, $mode);

            // Tot een paar pagina's geeft het antwoord de nieuwe tags per
            // item; daarboven laadt de browser de lijst opnieuw.
            if (count($ids) > 500 || ! BrowseSchema::hasRelations()) {
                return ['tags' => null, 'count' => count($ids)];
            }

            $tags = MediaItem::query()->whereKey($ids)->with('tags')->get()
                ->mapWithKeys(fn (MediaItem $item) => [(int) $item->getKey() => $item->tags->pluck('name')->sort()->values()->all()])
                ->all();

            return ['tags' => (object) $tags, 'count' => count($ids)];
        });
    }

    #[Renderless]
    public function toggleFavorite(int $id): array
    {
        $this->authorizeMedia('view');

        return $this->mediaGuarded(fn () => [
            'id' => $id,
            'is_favorite' => ($userId = $this->mediaUserId()) !== null
                && app(MediaLibrary::class)->toggleFavorite((int) $this->mediaItemOrFail($id)->getKey(), $userId),
        ]);
    }

    #[Renderless]
    public function setFavorite(array $ids, bool $on = true): array
    {
        $this->authorizeMedia('view');

        return $this->mediaGuarded(function () use ($ids, $on) {
            $ids = $this->mediaIds($ids);

            if (($userId = $this->mediaUserId()) !== null) {
                app(MediaLibrary::class)->setFavorite($ids, $userId, $on);
            }

            return ['count' => count($ids), 'is_favorite' => $on];
        });
    }

    /**
     * Verplaatsen is ordenen en hoort bij bewerken (`edit_media`). Gaat een
     * bestand daarbij een privé-map in of uit, dan verandert wat de site
     * kan tonen: dat vraagt ook `manage_media_folders` en een bevestiging.
     * Zonder $confirmed komt die bevestiging terug in plaats van de verplaatsing.
     */
    #[Renderless]
    public function moveItems(array $ids, int|string|null $folderId = null, bool $confirmed = false): array
    {
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($ids, $folderId, $confirmed) {
            $ids = $this->mediaIds($ids);
            $target = $this->mediaFolderId($folderId);

            abort_unless(app(MediaLibrary::class)->canUpdateAll(auth()->user(), $ids), 403);

            $visibility = app(MediaVisibility::class);
            $plan = $visibility->planMove($ids, $target);

            if ($plan['to_private'] !== [] || $plan['to_public'] !== []) {
                $this->authorizeMedia('folders');

                if (! $confirmed) {
                    return ['ok' => false, 'confirm' => $this->mediaVisibilityNotice($plan['to_private'], $plan['to_public'])];
                }
            }

            $moved = app(MediaBulk::class)->moveItems($ids, $target);
            $relocation = $visibility->sync($ids);

            return [
                'moved' => $moved,
                'relocation' => $relocation,
                'tree' => $this->mediaTree(),
            ];
        });
    }

    /**
     * Zet het focuspunt van een afbeelding. Zonder waarden (of 50/50): terug naar het midden.
     */
    #[Renderless]
    public function setFocalPoint(int $id, ?int $x = null, ?int $y = null): array
    {
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($id, $x, $y) {
            $item = $this->mediaItemOrFail($id);
            $library = app(MediaLibrary::class);

            abort_unless($library->canUpdate(auth()->user(), $item), 403);

            return ['item' => $this->mediaDetail($this->mediaLoaded($library->setFocalPoint($item, $x, $y)))];
        });
    }

    /**
     * Bijsnijden, draaien en spiegelen. Het recept wordt in de achterkant
     * gevalideerd; de browser stuurt alleen getallen.
     *
     * @param  array<string, mixed>  $recipe
     */
    #[Renderless]
    public function applyEdits(int $id, array $recipe = []): array
    {
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($id, $recipe) {
            $item = $this->mediaItemOrFail($id);
            $library = app(MediaLibrary::class);

            abort_unless($library->canUpdate(auth()->user(), $item), 403);

            return ['item' => $this->mediaDetail($this->mediaLoaded($library->applyEdits($item, [
                'rotate' => $recipe['rotate'] ?? 0,
                'flip' => $recipe['flip'] ?? null,
                'crop' => $recipe['crop'] ?? null,
            ])))];
        });
    }

    #[Renderless]
    public function revertEdits(int $id): array
    {
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($id) {
            $item = $this->mediaItemOrFail($id);
            $library = app(MediaLibrary::class);

            abort_unless($library->canUpdate(auth()->user(), $item), 403);

            return ['item' => $this->mediaDetail($this->mediaLoaded($library->revertEdits($item)))];
        });
    }

    /**
     * Kiest een afbeelding uit de bibliotheek als poster van een video of
     * pdf. Zonder $posterId: de eigen poster weg, terug naar de automatische.
     */
    #[Renderless]
    public function setPoster(int $id, ?int $posterId = null): array
    {
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($id, $posterId) {
            $item = $this->mediaItemOrFail($id);
            $library = app(MediaLibrary::class);

            abort_unless($library->canUpdate(auth()->user(), $item), 403);

            return ['item' => $this->mediaDetail($this->mediaLoaded($library->setPoster($item, $posterId)))];
        });
    }

    /** Laat de server de poster of miniatuur opnieuw maken (in de wachtrij). */
    #[Renderless]
    public function regeneratePreview(int $id): array
    {
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($id) {
            $item = $this->mediaItemOrFail($id);
            $library = app(MediaLibrary::class);

            abort_unless($library->canUpdate(auth()->user(), $item), 403);

            $library->regeneratePreview($item);

            return ['message' => __('De voorvertoning wordt opnieuw gemaakt. Dat kan even duren.')];
        });
    }

    /**
     * De stand van de pomp, voor de statusregel: de lopende run, de laatste
     * mislukte, en of er formaten ontbreken.
     */
    #[Renderless]
    public function loadConversionStatus(): array
    {
        $this->authorizeMedia('manage');

        if (! BrowseSchema::hasRuns()) {
            return ['ok' => true, 'run' => null, 'failed' => null, 'missing' => false, 'avif' => false];
        }

        $pump = app(\Dashed\DashedFiles\Classes\Conversions\ConversionPump::class);
        $describe = fn (?\Dashed\DashedFiles\Models\MediaConversionRun $run) => $run ? [
            'id' => (int) $run->getKey(),
            'type' => (string) $run->type,
            'status' => (string) $run->status,
            'done' => (int) $run->done,
            'total' => (int) $run->total,
            'failed' => (int) $run->failed,
            'error' => filled($run->last_error) ? mb_substr((string) $run->last_error, 0, 200) : null,
        ] : null;

        $active = $pump->active();

        return [
            'ok' => true,
            'run' => $describe($active),
            // Alleen de nieuwste, en alleen als hij van het laatste etmaal is: een oude mislukking is geen nieuws.
            'failed' => $active ? null : $describe(\Dashed\DashedFiles\Models\MediaConversionRun::query()
                ->where('status', \Dashed\DashedFiles\Models\MediaConversionRun::FAILED)
                ->where('updated_at', '>', now()->subDay())
                ->orderByDesc('id')
                ->first()),
            'missing' => $active ? false : $pump->hasMissing(),
            'avif' => MediaLibrary::avifEnabled() && \Dashed\DashedFiles\Classes\MediaCapabilities::avif(),
        ];
    }

    /**
     * Start een run van de pomp vanuit de beheerpagina.
     *
     * @param  array<int, int|string>  $ids  leeg is de hele bibliotheek
     */
    #[Renderless]
    public function startConversions(string $type, array $ids = []): array
    {
        // Formaten (opnieuw) laten maken wijzigt bestanden: bewerken, niet alleen bekijken.
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($type, $ids) {
            // Het focuspunt start zijn eigen run; die soort is hier niet te kiezen.
            if (! in_array($type, ['missing', 'force', 'avif', 'cleanup'], true)) {
                throw ValidationException::withMessages(['type' => __('Deze actie bestaat niet.')]);
            }

            $ids = $this->mediaIds($ids);
            $this->mediaAuthorizeEach($ids, 'edit');

            // Alles opnieuw maken (een `force` zonder selectie) is uren werk
            // voor de werker: dat vraagt hetzelfde recht als definitief
            // verwijderen, of de commandoregel.
            if ($type === 'force' && $ids === [] && ! static::mayManage('purge')) {
                throw ValidationException::withMessages(['type' => __('Alle formaten van de hele bibliotheek opnieuw genereren kan alleen met het recht om definitief te verwijderen, of vanaf de commandoregel.')]);
            }

            $run = app(MediaLibrary::class)->startConversionRun($type, ['ids' => $ids], $this->mediaUserId());

            Notification::make()->title(__('De formaten worden op de achtergrond gegenereerd.'))->success()->send();

            return ['run' => ['id' => (int) $run->getKey(), 'type' => (string) $run->type, 'status' => (string) $run->status, 'done' => (int) $run->done, 'total' => (int) $run->total, 'failed' => (int) $run->failed, 'error' => null]];
        });
    }

    #[Renderless]
    public function cancelConversions(int $runId): array
    {
        // Formaten (opnieuw) laten maken wijzigt bestanden: bewerken, niet alleen bekijken.
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($runId) {
            app(MediaLibrary::class)->cancelConversionRun($runId);

            return [];
        });
    }

    #[Renderless]
    public function resumeConversions(int $runId): array
    {
        // Formaten (opnieuw) laten maken wijzigt bestanden: bewerken, niet alleen bekijken.
        $this->authorizeMedia('edit');

        return $this->mediaGuarded(function () use ($runId) {
            app(MediaLibrary::class)->resumeConversionRun($runId);

            return [];
        });
    }

    #[Renderless]
    public function deleteItem(int $id): array
    {
        $this->authorizeMedia('delete');

        return $this->mediaGuarded(function () use ($id) {
            $item = $this->mediaItemOrFail($id);

            abort_unless(app(MediaLibrary::class)->canDelete(auth()->user(), $item), 403);

            app(MediaBulk::class)->deleteItem($item, $this->mediaUserId());

            return ['id' => $id, 'tree' => $this->mediaTree()];
        });
    }

    #[Renderless]
    public function createFolder(string $name, ?int $parentId = null): array
    {
        $this->authorizeMedia('folders');

        return $this->mediaGuarded(function () use ($name, $parentId) {
            $folder = app(MediaFolderTree::class)->create($name, $parentId);

            return ['folder' => ['id' => (int) $folder->getKey(), 'name' => (string) $folder->name], 'tree' => $this->mediaTree()];
        });
    }

    #[Renderless]
    public function renameFolder(int $id, string $name): array
    {
        $this->authorizeMedia('folders');

        return $this->mediaGuarded(function () use ($id, $name) {
            $folder = app(MediaFolderTree::class)->rename($this->mediaFolderOrFail($id), $name);

            return ['folder' => ['id' => (int) $folder->getKey(), 'name' => (string) $folder->name], 'tree' => $this->mediaTree()];
        });
    }

    #[Renderless]
    public function moveFolder(int $id, ?int $parentId = null, bool $confirmed = false): array
    {
        $this->authorizeMedia('folders');

        return $this->mediaGuarded(function () use ($id, $parentId, $confirmed) {
            $folder = $this->mediaFolderOrFail($id);
            $visibility = app(MediaVisibility::class);
            // Een map met inhoud een privé-map in of uit slepen verhuist alles eronder.
            $changes = $visibility->folderChanges([$id => ['parent_id' => $parentId]]);

            if (! $confirmed && ($notice = $this->mediaFolderNotice($changes)) !== null) {
                return ['ok' => false, 'confirm' => $notice];
            }

            app(MediaFolderTree::class)->move($folder, $parentId);
            $relocation = $visibility->sync(null, array_merge($changes['to_private'], $changes['to_public']));

            return ['relocation' => $relocation, 'tree' => $this->mediaTree()];
        });
    }

    /**
     * Wat het venster "Instellingen van de map" toont: de eigen keuze, wat
     * er van boven geërfd wordt en welke sites er te kiezen zijn.
     */
    #[Renderless]
    public function loadFolderSettings(int $id): array
    {
        $this->authorizeMedia('folders');

        return $this->mediaGuarded(function () use ($id) {
            $folder = $this->mediaFolderOrFail($id);
            $map = app(MediaVisibility::class)->folders();
            $own = $map[$id] ?? ['own_private' => false, 'private' => false, 'own_sites' => null, 'sites' => null];
            $parent = $folder->parent_id === null ? null : ($map[(int) $folder->parent_id] ?? null);
            $parentSites = $parent['sites'] ?? null;
            $names = MediaSites::all();

            return ['settings' => [
                'id' => $id,
                'name' => (string) $folder->name,
                'private' => (bool) $own['own_private'],
                'inherited_private' => (bool) ($parent['private'] ?? false),
                'sites' => $own['own_sites'],
                // Alleen wat de ouder toelaat is te kiezen.
                'site_options' => count($names) > 1
                    ? array_values(array_map(fn (string $siteId) => ['id' => $siteId, 'label' => $names[$siteId]], $parentSites ?? array_keys($names)))
                    : [],
                'inherited_sites' => $parentSites === null ? null : MediaSites::label($parentSites),
            ]];
        });
    }

    /**
     * Zet de sites en de privé-vlag van een map. Wisselt de map (of iets
     * eronder) daardoor van privé naar publiek of andersom, dan komt eerst
     * de bevestiging terug: hoeveel bestanden verhuizen en op hoeveel
     * plekken de site ze nu nog toont.
     *
     * @param  array<string, mixed>  $settings  private (bool) en sites (lijst site-id's, leeg of null voor alle)
     */
    #[Renderless]
    public function saveFolderSettings(int $id, array $settings = [], bool $confirmed = false): array
    {
        $this->authorizeMedia('folders');

        return $this->mediaGuarded(function () use ($id, $settings, $confirmed) {
            $folder = $this->mediaFolderOrFail($id);
            $visibility = app(MediaVisibility::class);
            $current = $visibility->folders()[$id] ?? ['own_private' => false, 'own_sites' => null];

            $private = array_key_exists('private', $settings) ? filter_var($settings['private'], FILTER_VALIDATE_BOOLEAN) : (bool) $current['own_private'];
            // Bij één site is er niets te kiezen: wat er stond blijft staan.
            $sites = MediaSites::isMultiSite() && array_key_exists('sites', $settings)
                ? MediaSites::normalize(is_array($settings['sites']) ? array_slice($settings['sites'], 0, 100) : null)
                : $current['own_sites'];

            $changes = $visibility->folderChanges([$id => ['is_private' => $private]]);

            if (! $confirmed && ($notice = $this->mediaFolderNotice($changes)) !== null) {
                return ['ok' => false, 'confirm' => $notice];
            }

            $result = app(MediaFolderTree::class)->updateSettings($folder, $sites, $private);
            $relocation = $visibility->sync(null, array_merge($changes['to_private'], $changes['to_public']));

            return ['pruned' => $result['pruned'], 'relocation' => $relocation, 'tree' => $this->mediaTree()];
        });
    }

    /**
     * Zet het verhuizen van bestanden die nog wachten opnieuw in gang, ook
     * voor wat eerder mislukte.
     */
    #[Renderless]
    public function retryRelocation(): array
    {
        $this->authorizeMedia('folders');

        \Dashed\DashedFiles\Jobs\RelocateMediaItems::dispatch(0, true);

        return ['ok' => true, 'tree' => $this->mediaTree()];
    }

    #[Renderless]
    public function deleteFolder(int $id): array
    {
        $this->authorizeMedia('folders');
        // Een map weggooien gooit ook elk bestand erin weg.
        $this->authorizeMedia('delete');

        return $this->mediaGuarded(function () use ($id) {
            $folder = $this->mediaFolderOrFail($id);

            if (app(MediaLibrary::class)->hasItemPolicy()) {
                $tree = app(MediaFolderTree::class);
                $itemIds = MediaItem::query()->whereIn('folder_id', $tree->subtreeIds($id))->limit(self::MEDIA_MAX_IDS + 1)->pluck('id')->all();
                $this->mediaAuthorizeEach($itemIds, 'delete');
            }

            $result = app(MediaFolderTree::class)->delete($folder, $this->mediaUserId());

            return ['id' => $id, 'folders' => $result['folders'], 'items' => $result['items'], 'tree' => $this->mediaTree()];
        });
    }

    /**
     * "Alles in dit filter selecteren": de id's, ook van wat nog niet geladen is.
     */
    #[Renderless]
    public function selectAll(array $filters = []): array
    {
        $this->authorizeMedia('view');

        $ids = MediaItemQuery::make($this->mediaFilters($filters), $this->mediaUserId())->ids(self::MEDIA_MAX_IDS);

        return ['ids' => $ids, 'count' => count($ids), 'capped' => count($ids) >= self::MEDIA_MAX_IDS];
    }

    /**
     * Zet een zip klaar en geeft een ondertekende link terug die de browser
     * opent. Niet als download via Livewire: die houdt het hele bestand als
     * base64 in het geheugen, en dat haalt een selectie van honderden MB niet.
     *
     * @return array{ok: bool, url?: string, count?: int}
     */
    #[Renderless]
    public function downloadZip(array $ids): array
    {
        $this->authorizeMedia('manage');

        $ids = $this->mediaIds($ids);

        if ($ids === []) {
            return ['ok' => false];
        }

        if (! class_exists(ZipArchive::class)) {
            return $this->mediaRefuse(__('Zip-bestanden maken kan op deze server niet.'));
        }

        if (count($ids) > self::MEDIA_ZIP_MAX_FILES) {
            return $this->mediaRefuse(__('Download hooguit :max bestanden tegelijk.', ['max' => self::MEDIA_ZIP_MAX_FILES]));
        }

        $items = MediaItem::query()->with('media')->whereKey($ids)->get()->filter(fn (MediaItem $item) => $item->file() !== null);

        if ($items->isEmpty()) {
            return $this->mediaRefuse(__('Geen van deze bestanden bestaat nog.'));
        }

        if ($items->sum(fn (MediaItem $item) => (int) ($item->size ?? $item->file()->size)) > self::MEDIA_ZIP_MAX_BYTES) {
            return $this->mediaRefuse(__('Deze selectie is samen groter dan :max MB. Download in kleinere delen.', ['max' => (int) (self::MEDIA_ZIP_MAX_BYTES / 1024 / 1024)]));
        }

        // Eén verzoek kan meerdere aanroepen dragen. Daarom per gebruiker:
        // hooguit een paar zips per minuut, nooit twee tegelijk, en nooit
        // meer dan één klaargezette zip op de schijf.
        $user = (string) ($this->mediaUserId() ?? 'guest');
        $userKey = 'media-zip:' . $user;
        $lastKey = 'media-zip-last:' . $user;

        if (RateLimiter::tooManyAttempts($userKey, self::MEDIA_ZIP_PER_MINUTE)) {
            return $this->mediaRefuse(__('Je hebt net al een paar zips aangevraagd. Probeer het over een minuut opnieuw.'));
        }

        $lock = Cache::lock('media-zip-lock:' . $user, 120);

        if (! $lock->get()) {
            return $this->mediaRefuse(__('Er wordt al een zip voor je klaargezet. Wacht tot die klaar is.'));
        }

        $local = null;
        $zipPath = null;
        $added = 0;
        $done = false;

        try {
            RateLimiter::hit($userKey, 60);

            // De vorige zip van deze gebruiker die nooit is opgehaald.
            $previous = Cache::pull($lastKey);

            if (is_string($previous) && preg_match('/^[A-Za-z0-9]{40}$/', $previous)) {
                @unlink(storage_path('app/media-ingest') . DIRECTORY_SEPARATOR . $previous);
            }

            $zipPath = IncomingMedia::temporaryPath();
            $used = [];

            foreach ($items as $item) {
                $file = $item->file();

                try {
                    $stream = Storage::disk($file->disk)->readStream($file->getPathRelativeToRoot());
                } catch (Throwable) {
                    $stream = null;
                }

                if (! is_resource($stream)) {
                    continue;
                }

                // ZipArchive neemt geen stream aan en leest een toegevoegd
                // bestand pas bij close(). Daarom per bestand: kopie maken,
                // toevoegen, sluiten en de kopie meteen weer weg, zodat er
                // nooit meer dan één kopie naast de zip staat.
                $local = IncomingMedia::temporaryPath();
                $target = fopen($local, 'wb');
                stream_copy_to_stream($stream, $target);
                fclose($stream);
                fclose($target);

                $zip = new ZipArchive();

                if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                    return $this->mediaRefuse(__('Het zip-bestand kon niet worden gemaakt.'));
                }

                $zip->addFile($local, $this->mediaZipName((string) $file->file_name, $used));
                $zip->close();

                @unlink($local);
                $local = null;
                $added++;
            }

            if ($added === 0) {
                return $this->mediaRefuse(__('Geen van deze bestanden kon worden gelezen.'));
            }

            Cache::put($lastKey, basename($zipPath), now()->addHour());
            $done = true;
        } catch (MediaRejectedException $exception) {
            return $this->mediaRefuse($exception->getMessage());
        } finally {
            // Ook bij een fout halverwege: geen kopie en geen halve zip achterlaten.
            if ($local !== null) {
                @unlink($local);
            }

            if (! $done && $zipPath !== null) {
                @unlink($zipPath);
            }

            $lock->release();
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        return [
            'ok' => true,
            'count' => $added,
            'url' => URL::temporarySignedRoute(
                $panel->generateRouteName(MediaZipController::ROUTE),
                now()->addMinutes(MediaZipController::VALID_MINUTES),
                ['token' => basename($zipPath), 'user' => $this->mediaUserId()],
            ),
        ];
    }

    /**
     * Voert een actie uit het register uit. De browser stuurt alleen de
     * plek en de sleutel; de closure wordt hier op naam opgezocht.
     */
    #[Renderless]
    public function runMediaAction(string $slot, string $key, array $ids = [], array $data = []): array
    {
        $this->authorizeMedia('edit');

        $action = MediaActionRegistry::find($slot, $key);

        if (! $action) {
            return ['ok' => false];
        }

        return $this->mediaGuarded(function () use ($slot, $action, $ids, $data) {
            $ids = $this->mediaIds($ids);
            // De browser vraagt met `_ask` eerst de bevestigingstekst op van
            // een actie waarvan die tekst van de selectie afhangt.
            $asks = ($data['_ask'] ?? false) === true;
            $data = $asks ? [] : $this->mediaActionData($action, $data);
            $library = app(MediaLibrary::class);

            if ($slot === MediaActionRegistry::ITEM) {
                $subject = $this->mediaItemOrFail($ids[0] ?? 0);

                if ($action['visible'] && ! ($action['visible'])($subject)) {
                    throw ValidationException::withMessages(['action' => __('Deze actie kan niet op dit bestand.')]);
                }

                abort_unless($library->canUpdate(auth()->user(), $subject), 403);
            } else {
                $subject = $slot === MediaActionRegistry::BULK ? $library->findMany($ids)->values() : new Collection();

                if ($slot === MediaActionRegistry::BULK && $subject->isEmpty()) {
                    throw ValidationException::withMessages(['action' => __('Selecteer eerst een of meer bestanden.')]);
                }

                if ($library->hasItemPolicy()) {
                    $subject->each(fn (MediaItem $item) => abort_unless($library->canUpdate(auth()->user(), $item), 403));
                }
            }

            if ($asks) {
                $confirm = $action['confirm'] instanceof Closure ? rescue(fn () => ($action['confirm'])($subject), null) : $action['confirm'];

                return ['confirm' => filled($confirm) ? (string) $confirm : null];
            }

            try {
                $message = ($action['handle'])($subject, $data);
            } catch (ValidationException|MediaRejectedException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                report($exception);

                throw ValidationException::withMessages(['action' => __('De actie is mislukt. Probeer het later opnieuw.')]);
            }

            if (is_string($message) && $message !== '') {
                Notification::make()->title($message)->success()->send();
            }

            return ['message' => is_string($message) ? $message : null];
        });
    }

    /**
     * Wat de browser bij het opstarten krijgt. Geen query's: de lijst en de
     * boom komen daarna in een eigen verzoek.
     *
     * @return array<string, mixed>
     */
    public function mediaBrowserConfig(string $mode = 'manage'): array
    {
        return [
            'mode' => $mode === 'pick' ? 'pick' : 'manage',
            'perPage' => self::MEDIA_PER_PAGE,
            'maxIds' => self::MEDIA_MAX_IDS,
            'zipMaxFiles' => self::MEDIA_ZIP_MAX_FILES,
            'uploadMaxKb' => static::mediaUploadMaxKb(),
            'locale' => str_replace('_', '-', app()->getLocale()),
            // De talen van de inhoud, de eerste voorop. Bij één taal toont het detailpaneel geen taalkiezer.
            'locales' => array_map(fn (string $id, string $label) => ['id' => $id, 'label' => $label], array_keys($labels = MediaTexts::localeLabels()), array_values($labels)),
            // Null: de prullenbak leegt zichzelf niet (de standaard).
            'trashDays' => MediaItemQuery::trashDays(),
            'replacedDays' => 30,
            'canPurge' => static::mayManage('purge'),
            // Wat de knoppen laten zien. De server toetst elke handeling zelf opnieuw.
            'can' => [
                'upload' => static::mayManage('upload'),
                'edit' => static::mayManage('edit'),
                'delete' => static::mayManage('delete'),
                'folders' => static::mayManage('folders'),
                'purge' => static::mayManage('purge'),
            ],
            // Alleen bij meer dan één site: het sitefilter en het veld Sites op een map.
            'sites' => MediaSites::isMultiSite() && BrowseSchema::hasAccess()
                ? array_map(fn (string $id, string $label) => ['id' => $id, 'label' => $label], array_keys($sites = MediaSites::all()), array_values($sites))
                : [],
            'activeSite' => MediaSites::isMultiSite() ? MediaSites::active() : null,
            'access' => BrowseSchema::hasAccess(),
            // Fase 9b: heeft de server geen ffmpeg, dan maakt de browser bij het uploaden zelf een poster van een video.
            'browserPosters' => \Dashed\DashedFiles\Classes\Preview\MediaPreviews::browserMakesPosters(),
            // Fase 11: de externe bronnen (URL, stock, AI) die hier nu te gebruiken zijn. Leeg zonder het recht om te uploaden.
            'sources' => method_exists($this, 'mediaSourcesConfig') ? $this->mediaSourcesConfig() : [],
            'sourceLabels' => method_exists($this, 'mediaSourceLabels') ? $this->mediaSourceLabels() : [],
            'actions' => [
                MediaActionRegistry::ITEM => MediaActionRegistry::describe(MediaActionRegistry::ITEM),
                MediaActionRegistry::BULK => MediaActionRegistry::describe(MediaActionRegistry::BULK),
                MediaActionRegistry::TOOLBAR => MediaActionRegistry::describe(MediaActionRegistry::TOOLBAR),
            ],
            'labels' => [
                'loadFailed' => __('De bestanden konden niet worden geladen.'),
                'saved' => __('Opgeslagen'),
                'copied' => __('URL gekopieerd'),
                'copyFailed' => __('Kopiëren is niet gelukt.'),
                'moved' => __('Verplaatst'),
                'deleted' => __('Verwijderd'),
                'folderCreated' => __('Map aangemaakt'),
                'folderDeleted' => __('Map verwijderd'),
                'tagsSaved' => __('Tags bijgewerkt'),
                'favoritesSaved' => __('Aan favorieten toegevoegd'),
                'selectedCount' => __(':count geselecteerd'),
                'selectedAll' => __('Alle :count bestanden in dit filter zijn geselecteerd.'),
                'selectedCapped' => __('De eerste :count bestanden in dit filter zijn geselecteerd; meer kan niet in één keer.'),
                'zipTooMany' => __('Download hooguit :max bestanden tegelijk.'),
                'uploading' => __('Bezig met uploaden'),
                'processing' => __('Verwerken'),
                'queued' => __('Wacht'),
                'uploaded' => __('Geüpload'),
                'duplicate' => __('Bestond al'),
                'uploadFailed' => __('Uploaden is mislukt. Probeer het opnieuw.'),
                'uploadRefused' => __('Dit bestand is geweigerd.'),
                'uploadTooLarge' => __('Te groot: maximaal :max MB per bestand.'),
                'zipReady' => __('De zip met :count bestanden wordt gedownload.'),
                'uploadsDone' => __(':done van :total bestanden geüpload'),
                'noFolder' => __('Zonder map'),
                'allFiles' => __('Alle bestanden'),
                'deleteItemTitle' => __('Bestand naar de prullenbak?'),
                'deleteItemBody' => __('Het bestand gaat naar de prullenbak en blijft op de site zichtbaar tot het definitief wordt verwijderd, na :days dagen. Tot dan kun je het terugzetten.'),
                'deleteItemBodyKept' => __('Het bestand gaat naar de prullenbak en blijft op de site zichtbaar. Het blijft daar bewaard tot iemand het definitief verwijdert; tot dan kun je het terugzetten.'),
                'indexCaveat' => __('"In gebruik" is gebaseerd op de velden die de mediabibliotheek kent. Een eigen veld van dit project kan ontbreken: een bestand kan dus ongebruikt lijken en toch ergens staan.'),
                'keptUntilPurged' => __('Blijft bewaard tot je het definitief verwijdert'),
                'inUseOnSite' => __('Nog in gebruik op de site'),
                'purgeInUse' => __(':count daarvan zijn nog in gebruik op de site: die afbeeldingen verdwijnen daar.'),
                'guess' => __('vermoedelijk'),
                'undoMerge' => __('Samenvoegen ongedaan maken'),
                'merged' => __('Dit bestand is samengevoegd met een ander bestand met dezelfde inhoud.'),
                'mergeAltDiffers' => __('De alt-teksten verschillen. Het bestand dat blijft houdt zijn eigen alt-tekst.'),
                'noAlt' => __('geen alt-tekst'),
                'noAltShort' => __('Geen alt'),
                'noAltTitle' => __('Geen alt-tekst'),
                'noAltIn' => __('Geen alt-tekst in: :locales'),
                'altFilled' => __('alt-tekst ingevuld'),
                'altEmpty' => __('alt-tekst ontbreekt'),
                'retryFailed' => __('Opnieuw proberen is niet gelukt.'),
                'deleteItemsTitle' => __(':count bestanden naar de prullenbak?'),
                'deleteInUse' => __(':count daarvan zijn nog in gebruik op de site.'),
                'deleteInUseOne' => __('Dit bestand is nog in gebruik op :count plekken:'),
                'deleteFolderTitle' => __('Map verwijderen?'),
                'deleteFolderBody' => __('De map gaat met alle submappen en bestanden naar de prullenbak. Je kunt hem daar in zijn geheel terugzetten.'),
                'trashed' => __('Naar de prullenbak verplaatst'),
                'restored' => __('Teruggezet'),
                'restore' => __('Terugzetten'),
                'purge' => __('Definitief verwijderen'),
                'purged' => __('Definitief verwijderd'),
                'purgeTitle' => __(':count bestanden definitief verwijderen?'),
                'purgeTitleOne' => __('Bestand definitief verwijderen?'),
                'purgeBody' => __('Dit kan niet ongedaan worden gemaakt. De bestanden verdwijnen ook van de site, op elke plek waar ze nog gebruikt worden.'),
                'purgeAt' => __('Wordt definitief verwijderd op :date'),
                'systemPurgeAt' => __('Door het systeem verwijderd, wordt op :date definitief opgeruimd'),
                'inTrash' => __('In prullenbak'),
                'trashedFolder' => __('Map met :count bestanden'),
                'usageTitle' => __('In gebruik op :count plekken'),
                'usageTitleOne' => __('In gebruik op 1 plek'),
                'usageNone' => __('Nergens in gebruik'),
                'usagePending' => __('Waar dit bestand gebruikt wordt is nog niet bekend: de index wordt opgebouwd.'),
                'usageMore' => __('en nog :count andere plekken'),
                'usageTrashed' => __('in prullenbak'),
                'unusedBadge' => __('Ongebruikt'),
                'replace' => __('Vervangen'),
                'replaceTitle' => __('Bestand vervangen?'),
                'replaceBody' => __('Het bestand krijgt een nieuw adres (URL). Velden, blokken, productafbeeldingen en instellingen tonen direct het nieuwe bestand. Tekst met een ingesloten afbeelding volgt binnen een paar minuten. Een link die je ergens anders hebt geplakt, en mails die al verstuurd zijn, wijzen nog :days dagen naar het oude bestand.'),
                'replaceInUse' => __('Dit bestand wordt op :count plekken gebruikt; daar verschijnt het nieuwe bestand.'),
                'replaced' => __('Bestand vervangen'),
                'replacing' => __('Bezig met vervangen'),
                'restoredFromTrash' => __('Stond in de prullenbak, teruggezet'),
                'addAnyway' => __('Toch als nieuw bestand toevoegen'),
                'merge' => __('Samenvoegen'),
                'mergeTitle' => __('Dubbele bestanden samenvoegen'),
                'mergeBody' => __('Kies welk bestand blijft. Elke bekende plek die een van de andere gebruikt gaat naar dit bestand wijzen; de andere gaan naar de prullenbak. Een bestand waarvan een plek niet zeker om te zetten is blijft staan. Vanuit de prullenbak is het samenvoegen ongedaan te maken.'),
                'mergeKeep' => __('Dit bestand blijft'),
                'delete' => __('Verwijderen'),
                'confirm' => __('Bevestigen'),
                'focalAt' => __(':x% van links, :y% van boven'),
                'focalSaved' => __('Focuspunt opgeslagen. De uitsneden worden op de achtergrond opnieuw gemaakt.'),
                'edited' => __('Afbeelding bewerkt'),
                'reverted' => __('Origineel hersteld'),
                'editResult' => __('Resultaat: :width × :height px'),
                'editNote' => __('Opslaan geeft de afbeelding een nieuw adres (URL). Velden, blokken en productafbeeldingen tonen direct de bewerkte afbeelding; het origineel blijft bewaard en is altijd te herstellen.'),
                'editInUse' => __('Deze afbeelding wordt op :count plekken gebruikt.'),
                'editInUseOne' => __('Deze afbeelding wordt op 1 plek gebruikt.'),
                'regenerate' => __('Opnieuw genereren'),
                'regenerateTitle' => __('Formaten opnieuw genereren?'),
                'regenerateBody' => __('Van :count afbeeldingen worden alle formaten opnieuw gemaakt. Dat gebeurt op de achtergrond, in kleine stappen.'),
                'regenerateBodyOne' => __('Van deze afbeelding worden alle formaten opnieuw gemaakt. Dat gebeurt op de achtergrond.'),
                'runProgress' => __(':done van :total'),
                'runFailedCount' => __(':count mislukt'),
                'runFailedTitle' => __('Het genereren van formaten is gestopt'),
                'runTypes' => [
                    'missing' => __('Ontbrekende formaten genereren'),
                    'force' => __('Formaten opnieuw genereren'),
                    'focal' => __('Uitsneden voor het focuspunt maken'),
                    'avif' => __('AVIF-varianten genereren'),
                    'cleanup' => __('Oude formaatbestanden opruimen'),
                ],
                'pages' => __(":count pagina's"),
                'onePage' => __('1 pagina'),
                'posterCustom' => __('Zelf gekozen afbeelding'),
                'posterAuto' => __('Automatisch gemaakt'),
                'posterNone' => __('Geen'),
                'posterSaved' => __('Poster ingesteld'),
                'posterRemoved' => __('Eigen poster verwijderd'),
                // Fase 10: privé, sites en het verhuizen van bestanden.
                'privateFolder' => __('Privé-map'),
                'privateItem' => __('Privé: de site kan dit bestand niet tonen'),
                'privateMoving' => __('De bestanden worden nog verplaatst. Tot dat klaar is blijft dit bestand afgeschermd.'),
                'moving' => __('wordt verplaatst'),
                'movingCount' => __(':count bestanden worden verplaatst tussen de publieke en de privé-opslag.'),
                'folderSettings' => __('Privé...'),
                'folderSettingsSites' => __('Sites en privé...'),
                'toPrivateTitle' => __('Bestanden privé maken?'),
                'toPublicTitle' => __('Bestanden publiek maken?'),
                'visibilityTitle' => __('Bestanden verplaatsen tussen privé en publiek?'),
                'toPrivateBody' => __(':count bestanden worden privé. De site kan ze daarna niet meer tonen.'),
                'toPrivateInUse' => __(':count daarvan worden nu op :places plekken op de site gebruikt. Daar verdwijnt de afbeelding of het bestand.'),
                'toPrivateUnused' => __('Voor zover bekend wordt geen van deze bestanden op de site gebruikt.'),
                'toPrivateUnknown' => __('Waar deze bestanden gebruikt worden is nog niet bekend (de lijst "waar gebruikt" is nog niet opgebouwd). Elke plek die ze toont laat daarna niets meer zien.'),
                'toPrivateCaveat' => __('Een bestand dat al is opgevraagd kan in een browser of bij een CDN nog een tijd bewaard blijven. Alleen de bron gaat hier weg.'),
                'toPublicBody' => __(':count privé-bestanden worden publiek. Iedereen die het adres kent kan ze dan openen.'),
                'toPublicEmpty' => __('De map is daarna niet meer privé: wat erin komt is voor iedereen met het adres te openen.'),
                'types' => [
                    'image' => __('Afbeelding'),
                    'video' => __('Video'),
                    'document' => __('Document'),
                    'svg' => __('SVG'),
                ],
                'smart' => [
                    'recent' => __('Recent'),
                    'mine' => __('Mijn uploads'),
                    'favorites' => __('Favorieten'),
                    'missing_alt' => __('Zonder alt-tekst'),
                    'unused' => __('Ongebruikt'),
                    'duplicates' => __('Dubbele bestanden'),
                    'trash' => __('Prullenbak'),
                ],
            ],
        ];
    }

    /**
     * De grootste upload die de uploadroute van Livewire doorlaat, in kB:
     * de `max:`-regel uit de configuratie, anders Livewire's standaard.
     */
    public static function mediaUploadMaxKb(): int
    {
        $rules = config('livewire.temporary_file_upload.rules');
        $rules = is_string($rules) ? explode('|', $rules) : (array) ($rules ?? []);

        foreach ($rules as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', $rule, $match)) {
                return (int) $match[1];
            }
        }

        return 12288;
    }

    /**
     * De actieknoppen per plek, voor de view: sleutel, label en pictogram.
     *
     * @return array<int, array{key: string, label: string, icon: ?string}>
     */
    public function mediaActionButtons(string $slot): array
    {
        return array_values(array_map(
            fn (array $action) => ['key' => $action['key'], 'label' => $action['label'], 'icon' => $action['icon']],
            MediaActionRegistry::for($slot),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function mediaDetail(MediaItem $item): array
    {
        $userId = $this->mediaUserId();
        $file = $item->file();

        $favorite = $userId !== null && BrowseSchema::hasRelations()
            && DB::table(BrowseSchema::FAVORITES)->where('user_id', $userId)->where('media_item_id', $item->getKey())->exists();

        $uploader = null;

        if ($item->uploaded_by_user_id) {
            $model = config('auth.providers.users.model');
            $user = is_string($model) && class_exists($model) ? $model::query()->find($item->uploaded_by_user_id) : null;
            $uploader = $user ? (string) ($user->name ?? $user->email ?? '') : null;
        }

        $actions = [];

        foreach (MediaActionRegistry::for(MediaActionRegistry::ITEM) as $action) {
            try {
                if (! $action['visible'] || ($action['visible'])($item)) {
                    $actions[] = $action['key'];
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return array_merge(MediaItemPayload::for($item, $favorite), [
            'title' => filled($item->title) ? (string) $item->title : null,
            'caption' => filled($item->caption) ? (string) $item->caption : null,
            'texts' => $this->mediaTexts($item),
            'source' => filled($item->source) ? (string) $item->source : null,
            // Fase 11: de herkomst als tekst, met alleen gecontroleerde links naar de aanbieder.
            'origin' => rescue(fn () => \Dashed\DashedFiles\Classes\Sources\MediaCredit::origin($item), null, false),
            'uploader' => $uploader ?: null,
            'breadcrumb' => app(MediaFolderTree::class)->breadcrumb($item->folder_id === null ? null : (int) $item->folder_id),
            // De mediarij is al geladen: de namen van wat er gegenereerd is kosten niets.
            'conversions' => $file ? array_keys(array_filter((array) $file->generated_conversions)) : [],
            'actions' => $actions,
            // De AI gaf het na drie pogingen op en er ontbreekt nog een taal: het paneel toont dat, met "Opnieuw proberen".
            'ai_alt_failed' => (int) $item->automatic_alt_tries >= 3 && in_array('generate-alt-text', $actions, true) && $item->missingAltLocales() !== [],
            'usage_count' => ($ready = app(MediaUsageIndexer::class)->isReady()) ? (int) (app(MediaLibrary::class)->usageCount([$item->getKey()])[$item->getKey()] ?? 0) : null,
            'usages' => $ready ? app(MediaLibrary::class)->usagesOf($item, self::MEDIA_USAGES_SHOWN) : [],
            'purge_at' => \Dashed\DashedFiles\Classes\Browse\MediaTrashOrigin::purgeAt($item, MediaItemQuery::trashDays())?->toIso8601String(),
            'trashed_by_system' => $item->trashed() && \Dashed\DashedFiles\Classes\Browse\MediaTrashOrigin::codePurgeAt($item) !== null,
            'in_use_on_site' => $item->trashed() && ($item->getAttribute('trashed_served_at') !== null || (int) (app(MediaLibrary::class)->usageCount([$item->getKey()])[$item->getKey()] ?? 0) > 0),
            // Alleen in de prullenbak: daar staat de knop om het samenvoegen terug te draaien.
            'merge_batch' => $item->trashed() ? app(\Dashed\DashedFiles\Classes\Usage\MediaDuplicates::class)->batchOf((int) $item->getKey()) : null,
        ], $this->mediaImageTools($item, $file), $this->mediaPreviewTools($item, $file));
    }

    /**
     * Wat het detailpaneel nodig heeft voor het focuspunt en het bewerken.
     *
     * @return array<string, mixed>
     */
    protected function mediaImageTools(MediaItem $item, ?\Spatie\MediaLibrary\MediaCollections\Models\Media $file): array
    {
        $mime = strtolower((string) ($file?->mime_type ?? ''));
        $raster = $file !== null && \Dashed\DashedFiles\Classes\MediaConversions::isConvertible($mime);
        $editable = $raster && in_array($mime, \Dashed\DashedFiles\Classes\MediaEdits::MIMES, true) && BrowseSchema::hasEdits();
        $recipe = $editable ? $item->editRecipe() : null;
        // Bewerken begint altijd bij de onaangetaste bron.
        $source = $recipe !== null ? rescue(fn () => app(MediaLibrary::class)->sourceOf($item), null, false) : null;

        return [
            'can_focus' => $raster && BrowseSchema::hasFocal() && ! $item->trashed(),
            'focal' => $raster ? $item->focalPoint() : null,
            'can_edit' => $editable && ! $item->trashed(),
            'edits' => $recipe,
            'edit_source' => match (true) {
                ! $editable => null,
                MediaVisibility::isPrivate($item) => MediaLibrary::privateUrl($item, null, $source ? 'source' : 'original'),
                default => rescue(fn () => ($source ?? $file)->getUrl(), null, false),
            },
        ];
    }

    /**
     * Wat het detailpaneel nodig heeft voor de poster van een video of de
     * miniatuur van een pdf.
     *
     * @return array<string, mixed>
     */
    protected function mediaPreviewTools(MediaItem $item, ?\Spatie\MediaLibrary\MediaCollections\Models\Media $file): array
    {
        $previews = app(\Dashed\DashedFiles\Classes\Preview\MediaPreviews::class);
        $kind = \Dashed\DashedFiles\Classes\Preview\MediaPreviews::kindOf($file?->mime_type);

        if ($kind === null) {
            return [];
        }

        $open = \Dashed\DashedFiles\Classes\Preview\MediaPreviews::available() && ! $item->trashed();

        return [
            'poster' => rescue(fn () => $previews->posterFor($item), null, false),
            'can_set_poster' => $open,
            'can_regenerate_preview' => $open && $previews->generatorForFile($item, $file) !== null,
        ];
    }

    /** Het item met zijn mediarij en tags geladen, voor mediaDetail(). */
    protected function mediaLoaded(MediaItem $item): MediaItem
    {
        return $item->loadMissing(BrowseSchema::hasRelations() ? ['media', 'tags'] : ['media']);
    }

    /**
     * Voert een mutatie uit en maakt van een afwijzing uit de achterkant
     * een melding in plaats van een foutpagina.
     *
     * @return array<string, mixed> altijd met `ok`
     */
    protected function mediaGuarded(Closure $callback): array
    {
        try {
            return array_merge(['ok' => true], $callback());
        } catch (ValidationException $exception) {
            $message = (string) (collect($exception->errors())->flatten()->first() ?: $exception->getMessage());
        } catch (MediaRejectedException $exception) {
            $message = $exception->getMessage();
        } catch (\Dashed\DashedFiles\Classes\MediaTextTooLongException $exception) {
            $message = __('De tekst mag maximaal :max tekens zijn.', ['max' => $exception->max]);
        }

        Notification::make()->title($message)->danger()->send();

        return ['ok' => false, 'error' => $message];
    }

    /**
     * @return array{ok: false, error: string}
     */
    protected function mediaRefuse(string $message): array
    {
        Notification::make()->title($message)->danger()->send();

        return ['ok' => false, 'error' => $message];
    }

    /**
     * Wat de bevestiging moet zeggen als bestanden van kant wisselen: hoeveel
     * er dicht gaan en op hoeveel plekken de site die nu nog toont (daar
     * verdwijnen ze), en hoeveel er open gaan.
     *
     * @param  array<int, int>  $toPrivate
     * @param  array<int, int>  $toPublic
     * @return array<string, mixed>
     */
    protected function mediaVisibilityNotice(array $toPrivate, array $toPublic): array
    {
        $ready = rescue(fn () => app(MediaUsageIndexer::class)->isReady(), false, false);
        $inUse = null;
        $places = null;

        if ($ready && $toPrivate !== []) {
            $counts = [];

            foreach (array_chunk($toPrivate, 1000) as $chunk) {
                $counts += app(MediaUsages::class)->counts($chunk);
            }

            $inUse = count($counts);
            $places = (int) array_sum($counts);
        }

        return [
            'to_private' => count($toPrivate),
            'to_public' => count($toPublic),
            // Null: de gebruiksindex is nog niet gevuld, dus het is niet te zeggen.
            'in_use' => $inUse,
            'places' => $places,
        ];
    }

    /**
     * @param  array{to_private: array<int, int>, to_public: array<int, int>}  $changes  mappen die van kant wisselen
     * @return array<string, mixed>|null null als er geen enkel bestand verhuist
     */
    protected function mediaFolderNotice(array $changes): ?array
    {
        $visibility = app(MediaVisibility::class);
        $toPrivate = $visibility->itemsChanging($changes['to_private'], true);
        $toPublic = $visibility->itemsChanging($changes['to_public'], false);

        // Een lege map die van kant wisselt verhuist niets en toont nergens
        // iets: opengaan vraagt toch een bevestiging, dichtgaan niet.
        if ($toPrivate === [] && $toPublic === [] && $changes['to_public'] === []) {
            return null;
        }

        return $this->mediaVisibilityNotice($toPrivate, $toPublic) + ['folders_to_public' => count($changes['to_public'])];
    }

    /** Of dit scherm privé-mappen en privé-bestanden toont. Een keuzevenster alleen met allowPrivate(). */
    protected function mediaShowsPrivate(): bool
    {
        return true;
    }

    /**
     * De slimme mappen Ongebruikt, Dubbel en Prullenbak horen bij de
     * beheerpagina. Een keuzevenster zet dit uit en krijgt ze dan ook niet
     * als de browser er zelf om vraagt.
     */
    protected function mediaManagesTrash(): bool
    {
        return $this->mediaMode() === 'manage';
    }

    protected function mediaTree(): array
    {
        return $this->mediaManagesTrash() ? app(MediaFolderTree::class)->manageTree() : app(MediaFolderTree::class)->tree($this->mediaShowsPrivate());
    }

    protected function mediaFilters(array $filters): array
    {
        // Wat de browser hierover stuurt telt niet: alleen het scherm zelf
        // beslist of privé-bestanden in de lijst staan.
        unset($filters['private']);

        if (! $this->mediaShowsPrivate()) {
            $filters['private'] = false;
        }

        if ($this->mediaManagesTrash()) {
            return $filters;
        }

        unset($filters['trashed'], $filters['usage']);

        if (in_array($filters['smart'] ?? null, MediaItemQuery::MANAGE_ONLY_SMART, true)) {
            unset($filters['smart']);
        }

        return $filters;
    }

    /**
     * Vraagt de policy voor elk item, als er een is.
     *
     * @param  array<int, int>  $ids
     */
    protected function mediaAuthorizeEach(array $ids, string $ability, bool $trashed = false): void
    {
        $library = app(MediaLibrary::class);

        if ($ids === [] || ! $library->hasItemPolicy()) {
            return;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($library->findMany($chunk, withTrashed: $trashed) as $item) {
                abort_unless($ability === 'delete' ? $library->canDelete(auth()->user(), $item) : $library->canUpdate(auth()->user(), $item), 403);
            }
        }
    }

    protected function mediaUserId(): ?int
    {
        return is_numeric($id = auth()->id()) ? (int) $id : null;
    }

    /**
     * @return array<int, int> positieve, unieke id's, hooguit MEDIA_MAX_IDS
     */
    protected function mediaIds(array $ids): array
    {
        $clean = [];

        foreach (array_slice($ids, 0, self::MEDIA_MAX_IDS) as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }

        return array_values($clean);
    }

    protected function mediaFolderId(int|string|null $folderId): ?int
    {
        if ($folderId === null || $folderId === '' || $folderId === 'root') {
            return null;
        }

        if ((is_int($folderId) || ctype_digit($folderId)) && (int) $folderId > 0) {
            return (int) $folderId;
        }

        throw ValidationException::withMessages(['folder_id' => __('De doelmap bestaat niet meer.')]);
    }

    protected function mediaItemOrFail(int $id): MediaItem
    {
        return app(MediaLibrary::class)->find($id)
            ?? throw ValidationException::withMessages(['id' => __('Dit bestand bestaat niet meer.')]);
    }

    protected function mediaFolderOrFail(int $id): \Dashed\DashedFiles\Models\MediaFolder
    {
        return app(MediaLibrary::class)->folder($id)
            ?? throw ValidationException::withMessages(['id' => __('Deze map bestaat niet meer.')]);
    }

    protected function mediaMime(MediaItem $item): ?string
    {
        return $item->mime_type ?? $item->file()?->mime_type;
    }

    /**
     * Alleen de velden die de actie zelf aanmeldt, als tekst.
     *
     * @return array<string, string>
     */
    protected function mediaActionData(array $action, array $data): array
    {
        $clean = [];

        foreach ($action['fields'] as $field) {
            $value = $data[$field['name']] ?? '';
            $value = is_string($value) || is_numeric($value) ? mb_substr(trim((string) $value), 0, 5000) : '';

            if ($field['type'] === 'select' && $value !== '' && ! in_array($value, array_column($field['options'], 'value'), true)) {
                $value = '';
            }

            if ($value === '' && $field['required']) {
                throw ValidationException::withMessages([$field['name'] => __(':veld is verplicht.', ['veld' => $field['label']])]);
            }

            $clean[$field['name']] = $value;
        }

        return $clean;
    }

    /**
     * Een bestandsnaam die in de zip nog niet voorkomt.
     *
     * @param  array<string, true>  $used
     */
    protected function mediaZipName(string $name, array &$used): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '-', $name) ?? '', " .\t") ?: 'bestand';
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
        $candidate = $name;

        for ($i = 2; isset($used[mb_strtolower($candidate)]); $i++) {
            $candidate = $base . ' (' . $i . ')' . ($extension === '' ? '' : '.' . $extension);
        }

        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
