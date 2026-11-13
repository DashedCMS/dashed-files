<?php

namespace Dashed\DashedFiles\Classes\Browse;

use Throwable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Dashed\DashedFiles\Classes\Usage\MediaUsages;
use Dashed\DashedFiles\Classes\Usage\MediaUsageIndexer;

/**
 * De lijstquery van de mediabibliotheek, opgebouwd uit een filter-array.
 * Die array komt uit de browser: alles gaat eerst door normalize(), en wat
 * daar niet doorheen komt bereikt de query niet.
 */
final class MediaItemQuery
{
    public const DEFAULT_PER_PAGE = 60;

    public const MIN_PER_PAGE = 12;

    public const MAX_PER_PAGE = 120;

    // Offset-paginering wordt per pagina duurder; dieper dan dit bladert niemand, die zoekt of filtert.
    public const MAX_OFFSET_ROWS = 20000;

    public const MAX_IDS = 5000;

    public const SMART = ['recent', 'mine', 'favorites', 'missing_alt', 'unused', 'duplicates', 'trash'];

    // Alleen voor de beheerpagina: het keuzevenster biedt deze niet aan.
    public const MANAGE_ONLY_SMART = ['unused', 'duplicates', 'trash'];

    public const USAGE = ['used', 'unused'];

    // Pas na een dag telt een bestand zonder verwijzing als ongebruikt: wie
    // net heeft geupload moet het nog ergens kunnen plaatsen.
    public const UNUSED_AFTER_HOURS = 24;

    // Deze bronnen en mappen vallen buiten "Ongebruikt": een AI-resultaat
    // zonder verwijzing is een bewaarde variant, en wat in `orders` of `chat`
    // staat is een bijlage bij een bestelling of een gesprek en wordt nergens
    // als veld gekozen. Zonder verwijzing zijn ze dus niet "over".
    public const PROTECTED_SOURCES = ['ai', 'chat'];

    public const PROTECTED_FOLDERS = ['orders', 'chat'];

    public const TYPES = ['image', 'video', 'document', 'svg'];

    public const SORTS = ['newest', 'oldest', 'name', 'size', 'largest_dimensions'];

    public const RECENT_DAYS = 30;

    private const MAX_SEARCH_LENGTH = 100;

    private const MAX_LIST = 25;

    private array $filters;

    private bool $withTotal = false;

    private function __construct(array $filters, private readonly ?int $userId)
    {
        $this->filters = self::normalize($filters);
    }

    public static function make(array $filters = [], ?int $userId = null): self
    {
        return new self($filters, $userId !== null && $userId > 0 ? $userId : null);
    }

    // Een COUNT op een grote gefilterde set is duur, dus alleen op verzoek.
    public function withTotal(bool $withTotal = true): self
    {
        $this->withTotal = $withTotal;

        return $this;
    }

    /**
     * @return array<string, mixed> de filters zoals ze na het opschonen gelden
     */
    public function filters(): array
    {
        return $this->filters;
    }

    public function page(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): MediaItemPage
    {
        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));
        $maxPage = max(1, intdiv(self::MAX_OFFSET_ROWS, $perPage));
        $page = max(1, min($maxPage, $page));

        $query = $this->query();

        // Eén rij extra zegt of er een volgende pagina is, zonder COUNT.
        $items = (clone $query)
            // De zelf gekozen poster van een video in dezelfde ronde: zonder gekozen posters kost dit geen query.
            ->with(array_merge(BrowseSchema::hasRelations() ? ['media', 'tags'] : ['media'], BrowseSchema::hasPreview() ? ['posterItem.media'] : []))
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->get();

        $hasMore = $items->count() > $perPage && $page < $maxPage;
        $items = $items->take($perPage);

        $favorites = $this->favoriteIds($items->modelKeys());
        // Eén GROUP BY voor de hele pagina, en alleen als de nulmeting klaar
        // is: tot dan zegt "geen verwijzing" niets.
        $usage = $items->isNotEmpty() && rescue(fn () => app(MediaUsageIndexer::class)->isReady(), false, false)
            ? app(MediaUsages::class)->counts($items->modelKeys())
            : null;
        // In de prullenbak telt ook een gok: "nog in gebruik" is daar de veilige kant.
        if ($this->isTrash() && $usage === null && $items->isNotEmpty() && BrowseSchema::hasUsages()) {
            $usage = app(MediaUsages::class)->counts($items->modelKeys());
        }

        $purgeDays = $this->isTrash() ? self::trashDays() : null;

        return new MediaItemPage(
            items: $items->map(fn (MediaItem $item) => MediaItemPayload::for(
                $item,
                isset($favorites[$item->getKey()]),
                $usage === null ? null : (int) ($usage[$item->getKey()] ?? 0),
                $purgeDays,
            ))->values()->all(),
            total: $this->withTotal ? (int) $query->toBase()->getCountForPagination() : null,
            hasMore: $hasMore,
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * De id's binnen het filter, voor "alles selecteren".
     *
     * @return array<int, int>
     */
    public function ids(int $limit = self::MAX_IDS): array
    {
        return $this->query()
            ->limit(max(1, min(self::MAX_IDS, $limit)))
            ->pluck((new MediaItem())->getTable() . '.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function query(): Builder
    {
        $filters = $this->filters;
        $query = $this->isTrash() && BrowseSchema::hasTrash() ? MediaItem::onlyTrashed() : MediaItem::query();

        if ($this->isTrash() && ! BrowseSchema::hasTrash()) {
            $query->whereRaw('1 = 0');
        }

        $this->applyFolder($query);
        $this->applySmart($query);
        $this->applyAccess($query);

        if (isset($filters['usage'])) {
            $this->applyUsage($query, $filters['usage'] === 'used');
        }

        if (isset($filters['type'])) {
            $this->applyType($query, $filters['type']);
        }

        if (isset($filters['mime'])) {
            $this->applyMimes($query, $filters['mime']);
        }

        foreach ($filters['tag'] ?? [] as $tagId) {
            $query->whereIn('id', fn ($sub) => $sub->select('media_item_id')->from(BrowseSchema::ITEM_TAG)->where('media_tag_id', $tagId));
        }

        $comparisons = [
            'uploader' => ['uploaded_by_user_id', '='],
            'source' => ['source', '='],
            'min_width' => ['width', '>='],
            'min_height' => ['height', '>='],
            'min_size' => ['size', '>='],
            'max_size' => ['size', '<='],
            'date_from' => ['created_at', '>='],
            'date_to' => ['created_at', '<='],
        ];

        foreach ($comparisons as $filter => [$column, $operator]) {
            if (isset($filters[$filter])) {
                $query->where($column, $operator, $filters[$filter]);
            }
        }

        // Gelijke bestanden bij elkaar, ook tijdens zoeken.
        if (($filters['smart'] ?? null) === 'duplicates') {
            if (isset($filters['search'])) {
                MediaSearch::applyTo($query, $filters['search']);
                $query->reorder();
            }

            return $query->orderBy('checksum')->orderBy('id');
        }

        if (isset($filters['search'])) {
            // Zet zelf de sortering op relevantie; die wint van `sort`, zoals overal in het CMS.
            MediaSearch::applyTo($query, $filters['search']);

            return $query->orderByDesc('id');
        }

        return $this->applySort($query);
    }

    /**
     * Van wat de browser stuurt naar wat de query mag zien. Een onbekende
     * sleutel of waarde valt weg; alleen `mime` sluit bij onzin alles uit,
     * want dat filter beperkt wat een kiezer mag aanbieden.
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $filters): array
    {
        $clean = [];

        $folder = $filters['folder'] ?? null;

        if ($folder === 'root') {
            $clean['folder'] = 'root';
        } elseif (($id = self::positiveInt($folder)) !== null) {
            $clean['folder'] = $id;

            if (filter_var($filters['include_subfolders'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $clean['include_subfolders'] = true;
            }
        }

        // `trashed` is hetzelfde als de slimme map Prullenbak.
        if (filter_var($filters['trashed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $filters['smart'] = 'trash';
        }

        foreach (['smart' => self::SMART, 'type' => self::TYPES, 'sort' => self::SORTS, 'usage' => self::USAGE] as $key => $allowed) {
            if (is_string($filters[$key] ?? null) && in_array($filters[$key], $allowed, true)) {
                $clean[$key] = $filters[$key];
            }
        }

        if (is_string($filters['search'] ?? null) || is_numeric($filters['search'] ?? null)) {
            $search = trim(mb_substr(trim((string) $filters['search']), 0, self::MAX_SEARCH_LENGTH));

            if ($search !== '') {
                $clean['search'] = $search;
            }
        }

        if (array_key_exists('mime', $filters) && filled($filters['mime'])) {
            $clean['mime'] = self::mimes(is_array($filters['mime']) ? $filters['mime'] : [$filters['mime']]);

            // */* is "alles": dan is er geen filter.
            if (in_array('*/*', $clean['mime'], true)) {
                unset($clean['mime']);
            }
        }

        $tags = array_values(array_unique(array_filter(array_map(
            fn ($id) => self::positiveInt($id),
            is_array($filters['tag'] ?? null) ? array_slice($filters['tag'], 0, self::MAX_LIST) : [$filters['tag'] ?? null],
        ))));

        if ($tags !== []) {
            $clean['tag'] = $tags;
        }

        foreach (['uploader', 'min_width', 'min_height', 'min_size', 'max_size'] as $key) {
            if (($value = self::positiveInt($filters[$key] ?? null)) !== null) {
                $clean[$key] = $value;
            }
        }

        if (is_string($filters['source'] ?? null) && preg_match('/^[a-z0-9][a-z0-9:_.-]{0,39}$/i', $filters['source'])) {
            $clean['source'] = $filters['source'];
        }

        // Privé erbij of juist niet: alleen een echte boolean telt, en die zet de server.
        if (is_bool($filters['private'] ?? null)) {
            $clean['private'] = $filters['private'];
        }

        // Eén site of een lijst; onbekende id's vallen weg en "alle sites" is geen filter.
        if (isset($filters['site']) && \Dashed\DashedFiles\Classes\Access\MediaSites::isMultiSite()) {
            $sites = \Dashed\DashedFiles\Classes\Access\MediaSites::normalize(is_array($filters['site']) ? array_slice($filters['site'], 0, self::MAX_LIST) : [$filters['site']]);

            if ($sites !== null) {
                $clean['site'] = $sites;
            }
        }

        if ($from = self::date($filters['date_from'] ?? null)) {
            $clean['date_from'] = $from->startOfDay();
        }

        if ($to = self::date($filters['date_to'] ?? null)) {
            $clean['date_to'] = $to->endOfDay();
        }

        return $clean;
    }

    /**
     * Privé en sites. Beide filteren op wat er al op het item of in de boom
     * staat: `is_private` is een kolom, en de mappen die niet bij een site
     * horen komen uit de boom in het geheugen (één query op de mappen, geen
     * per map of per item). Zonder deze filters, en dus bij één site, komt
     * er niets bij de query.
     */
    private function applyAccess(Builder $query): void
    {
        if (! BrowseSchema::hasAccess()) {
            return;
        }

        $table = $query->getModel()->getTable();

        if (($this->filters['private'] ?? null) === false) {
            $query->where($table . '.is_private', false);
        } elseif (($this->filters['private'] ?? null) === true) {
            $query->where($table . '.is_private', true);
        }

        if (isset($this->filters['site'])) {
            $outside = app(\Dashed\DashedFiles\Classes\Access\MediaVisibility::class)->foldersOutsideSites($this->filters['site']);

            if ($outside !== []) {
                // Bestanden zonder map horen bij elke site.
                $query->where(fn (Builder $folder) => $folder->whereNull($table . '.folder_id')->orWhereNotIn($table . '.folder_id', $outside));
            }
        }
    }

    private function applyFolder(Builder $query): void
    {
        $folder = $this->filters['folder'] ?? null;

        if ($folder === null) {
            return;
        }

        if ($folder === 'root') {
            $query->whereNull('folder_id');

            return;
        }

        $path = empty($this->filters['include_subfolders']) ? null : app(MediaFolderTree::class)->pathOf($folder);

        if ($path === null) {
            $query->where('folder_id', $folder);

            return;
        }

        // Het pad bestaat alleen uit cijfers en schuine strepen, dus er valt niets te escapen.
        $query->whereIn('folder_id', fn ($sub) => $sub->select('id')->from('filament_media_library_folders')->where('path', 'like', $path . '%'));
    }

    private function applySmart(Builder $query): void
    {
        switch ($this->filters['smart'] ?? null) {
            case 'recent':
                $query->where('created_at', '>=', now()->subDays(self::RECENT_DAYS));

                break;
            case 'mine':
                $this->userId === null ? $query->whereRaw('1 = 0') : $query->where('uploaded_by_user_id', $this->userId);

                break;
            case 'favorites':
                $this->userId === null || ! BrowseSchema::hasRelations()
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('id', fn ($sub) => $sub->select('media_item_id')->from(BrowseSchema::FAVORITES)->where('user_id', $this->userId));

                break;
            case 'missing_alt':
                // Leeg in minstens één taal van de installatie; alleen afbeeldingen.
                $query->where('mime_type', 'like', 'image/%')->missingAltTextInAnyLocale();

                break;
            case 'unused':
                $this->applyUnused($query);

                break;
            case 'duplicates':
                $this->applyDuplicates($query);

                break;
        }
    }

    private function isTrash(): bool
    {
        return ($this->filters['smart'] ?? null) === 'trash';
    }

    private function indexReady(): bool
    {
        return BrowseSchema::hasUsages() && rescue(fn () => app(MediaUsageIndexer::class)->isReady(), false, false);
    }

    private function applyUsage(Builder $query, bool $used): void
    {
        // Zonder gevulde index is "geen verwijzing" geen uitspraak: het
        // filter op ongebruikt is dan leeg, net als de slimme map.
        if (! BrowseSchema::hasUsages() || (! $used && ! $this->indexReady())) {
            $query->whereRaw('1 = 0');

            return;
        }

        $table = $query->getModel()->getTable();
        $exists = fn ($sub) => $sub->selectRaw('1')->from(BrowseSchema::USAGES)->whereColumn(BrowseSchema::USAGES . '.media_item_id', $table . '.id');

        // Een afbeelding die als poster van een video of pdf dient is ook in gebruik.
        $poster = BrowseSchema::hasPreview() ? fn ($sub) => MediaUsages::posterUseExists($sub, $table) : null;

        if ($used) {
            $query->where(function (Builder $any) use ($exists, $poster) {
                $any->whereExists($exists);

                if ($poster) {
                    $any->orWhereExists($poster);
                }
            });

            return;
        }

        $query->whereNotExists($exists);

        if ($poster) {
            $query->whereNotExists($poster);
        }
    }

    /**
     * Zonder verwijzing in de index, ouder dan een dag en niet uit een
     * beschermde bron of map. Zolang de nulmeting niet klaar is, is de map
     * leeg: een lege index zou anders alles ongebruikt noemen.
     */
    private function applyUnused(Builder $query): void
    {
        if (! $this->indexReady()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->applyUsage($query, false);

        $query->where('created_at', '<', now()->subHours(self::UNUSED_AFTER_HOURS))
            ->where(fn (Builder $source) => $source->whereNull('source')->orWhereNotIn('source', self::PROTECTED_SOURCES));

        $folders = 'filament_media_library_folders';
        $roots = DB::table($folders)->whereNull('parent_id')->whereIn(DB::raw('LOWER(name)'), self::PROTECTED_FOLDERS)->pluck('id')->all();

        if ($roots === []) {
            return;
        }

        $query->where(fn (Builder $folder) => $folder
            ->whereNull('folder_id')
            ->orWhereNotIn('folder_id', function ($sub) use ($folders, $roots) {
                $sub->select('id')->from($folders)->where(function ($paths) use ($roots) {
                    $paths->whereIn('id', $roots);

                    // Het pad bestaat alleen uit cijfers en schuine strepen.
                    if (BrowseSchema::hasPaths()) {
                        foreach ($roots as $root) {
                            $paths->orWhere('path', 'like', '/' . (int) $root . '/%');
                        }
                    }
                });
            }));
    }

    /**
     * Items waarvan grootte en checksum bij meer dan één niet-weggegooid
     * item voorkomen.
     */
    private function applyDuplicates(Builder $query): void
    {
        // Samenvoegen leunt op de index; zonder gevulde index is deze map er niet.
        if (! $this->indexReady()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $table = $query->getModel()->getTable();
        // Een chatbijlage is van een bezoeker en verdwijnt met zijn gesprek:
        // die hoort nooit het doel of de bron van een samenvoeging te zijn.
        $notChat = fn ($source, string $column) => $source->where(fn ($inner) => $inner->whereNull($column)->orWhere($column, '!=', 'chat'));

        $notChat($query, $table . '.source');

        $query->whereNotNull('checksum')->whereIn('checksum', function ($sub) use ($table, $notChat) {
            $notChat($sub, 'd.source');

            $sub->select('d.checksum')->from($table . ' as d')
                ->whereNotNull('d.checksum')
                ->when(BrowseSchema::hasTrash(), fn ($live) => $live->whereNull('d.deleted_at'))
                ->groupBy('d.size', 'd.checksum')
                ->havingRaw('count(*) > 1');
        });
    }

    /**
     * Na hoeveel dagen de prullenbak van de mediabibliotheek een item
     * definitief verwijdert, of null als hij zichzelf niet leegt (de
     * standaard): dan blijft alles staan tot iemand het bewust verwijdert.
     */
    public static function trashDays(): ?int
    {
        if (! \Dashed\DashedFiles\Classes\MediaLibrary::trashAutoPurge()) {
            return null;
        }

        $registry = 'Dashed\\DashedCore\\Retention\\RetentionRegistry';

        $days = class_exists($registry)
            ? rescue(fn () => app($registry)->vind('media_trash')?->termijnen()[0]?->dagen(), null, false)
            : null;

        return is_int($days) && $days > 0 ? $days : 30;
    }

    private function applyType(Builder $query, string $type): void
    {
        match ($type) {
            'svg' => $query->where('mime_type', MediaItemPayload::SVG),
            'image' => $query->where('mime_type', 'like', 'image/%')->where('mime_type', '!=', MediaItemPayload::SVG),
            'video' => $query->where('mime_type', 'like', 'video/%'),
            'document' => $query->where('mime_type', 'not like', 'image/%')->where('mime_type', 'not like', 'video/%'),
        };
    }

    /**
     * @param  array<int, string>  $mimes  exact (image/png) of met joker (image/*)
     */
    private function applyMimes(Builder $query, array $mimes): void
    {
        if ($mimes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $exact = array_values(array_filter($mimes, fn (string $mime) => ! str_ends_with($mime, '/*')));

        $query->where(function (Builder $group) use ($mimes, $exact) {
            if ($exact !== []) {
                $group->whereIn('mime_type', $exact);
            }

            foreach (array_diff($mimes, $exact) as $wildcard) {
                $group->orWhere('mime_type', 'like', substr($wildcard, 0, -1) . '%');
            }
        });
    }

    private function applySort(Builder $query): Builder
    {
        // Het id loopt op met het moment van aanmaken en is de primaire
        // sleutel: sorteren daarop kost geen filesort.
        $sort = ($this->filters['smart'] ?? null) === 'recent' ? 'newest' : ($this->filters['sort'] ?? 'newest');

        return match ($sort) {
            'oldest' => $query->orderBy('id'),
            'name' => $query->orderByRaw('LOWER(COALESCE(title, file_name)) asc')->orderBy('id'),
            'size' => $query->orderByDesc('size')->orderByDesc('id'),
            'largest_dimensions' => $query->orderByRaw('(COALESCE(width, 0) * COALESCE(height, 0)) desc')->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, true> op item-id gesleuteld
     */
    private function favoriteIds(array $ids): array
    {
        if ($this->userId === null || $ids === [] || ! BrowseSchema::hasRelations()) {
            return [];
        }

        return array_fill_keys(
            DB::table(BrowseSchema::FAVORITES)->where('user_id', $this->userId)->whereIn('media_item_id', $ids)->pluck('media_item_id')->all(),
            true,
        );
    }

    /**
     * @return array<int, string>
     */
    private static function mimes(array $mimes): array
    {
        $clean = [];

        foreach (array_slice($mimes, 0, self::MAX_LIST) as $mime) {
            if (! is_string($mime)) {
                continue;
            }

            $mime = strtolower(trim($mime));

            if (preg_match('#^(\*/\*|[a-z0-9][a-z0-9.+-]{0,60}/(\*|[a-z0-9][a-z0-9.+-]{0,60}))$#', $mime)) {
                $clean[$mime] = $mime;
            }
        }

        return array_values($clean);
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0 ? (int) $value : null;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null;
        }

        try {
            return Carbon::parse(substr($value, 0, 10));
        } catch (Throwable) {
            return null;
        }
    }
}
