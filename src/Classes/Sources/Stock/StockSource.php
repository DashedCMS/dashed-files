<?php

namespace Dashed\DashedFiles\Classes\Sources\Stock;

use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Ingest\IngestResult;
use Dashed\DashedFiles\Classes\Ingest\IncomingMedia;
use Dashed\DashedFiles\Classes\Sources\MediaCredit;
use Dashed\DashedFiles\Classes\Access\MediaVisibility;
use Dashed\DashedFiles\Classes\Sources\MediaSourceException;
use Dashed\DashedFiles\Classes\Sources\MediaSourceSettings;
use Dashed\DashedFiles\Classes\Sources\SearchableMediaSource;

/**
 * Het gedeelde deel van een stockaanbieder: de cache van zoekresultaten,
 * het controleren van elk adres uit het antwoord, en het importeren.
 *
 * Alles wat de aanbieder terugstuurt is onbetrouwbaar. Een adres van een
 * afbeelding telt alleen als het https is en op een van de vaste
 * bestandsservers van de aanbieder staat; een link naar de maker of de
 * fotopagina alleen op het domein van de aanbieder. De browser krijgt nooit
 * het downloadadres en stuurt bij importeren alleen het id: de server zoekt
 * het adres zelf weer op.
 */
abstract class StockSource implements SearchableMediaSource
{
    public const PER_PAGE = 24;

    public const CACHE_SECONDS = 24 * 60 * 60;

    public const QUERY_MAX = 100;

    /** Grenzen op wat een antwoord van de aanbieder per veld mag vullen. */
    public const NAME_MAX = 120;

    public const ALT_MAX = 300;

    public const LINK_MAX = 500;

    public const ORIENTATIONS = ['landscape', 'portrait', 'square'];

    abstract public function providerName(): string;

    /** De homepage van de aanbieder. */
    abstract public function providerHome(): string;

    abstract public function licenseName(): string;

    /**
     * De domeinen waarop een link naar de maker of de fotopagina mag staan.
     *
     * @return array<int, string>
     */
    abstract public function siteHosts(): array;

    /**
     * De bestandsservers: de enige hosts waar een miniatuur van getoond en
     * een bestand van gedownload wordt.
     *
     * @return array<int, string>
     */
    abstract public function fileHosts(): array;

    /**
     * @param  array{orientation: ?string}  $options
     * @return array{items: array<int, array<string, mixed>>, hasMore: bool, total: ?int}  genormaliseerd, met `download`
     *
     * @throws MediaSourceException
     */
    abstract protected function fetchPage(string $query, int $page, array $options, string $apiKey, int $maxWidth): array;

    /**
     * @return array<string, mixed>|null  één genormaliseerd resultaat, met `download`
     *
     * @throws MediaSourceException
     */
    abstract protected function fetchOne(string $externalId, string $apiKey, int $maxWidth): ?array;

    public function label(): string
    {
        return $this->providerName();
    }

    public function icon(): string
    {
        return 'heroicon-o-photo';
    }

    public function kind(): string
    {
        return self::KIND_SEARCH;
    }

    public function isAvailable(?string $siteId = null): bool
    {
        return MediaSourceSettings::enabled($this->key(), $siteId) && MediaSourceSettings::hasKey($this->key(), $siteId);
    }

    public function browserConfig(?string $siteId = null): array
    {
        return [
            'title' => __('Zoeken bij :provider', ['provider' => $this->providerName()]),
            'placeholder' => __('Waar zoek je een foto van?'),
            'button' => __('Zoeken'),
            // De vermelding die de aanbieder in een zoekscherm vraagt, met een link naar zijn site.
            'attribution' => [
                'text' => __("Foto's van :provider", ['provider' => $this->providerName()]),
                'url' => MediaCredit::link($this->providerHome(), $this->siteHosts()),
            ],
            'license' => $this->licenseName(),
            'help' => __("De foto wordt naar de eigen bibliotheek gekopieerd. Gebruik geen foto's van herkenbare personen of merken op een manier die hen in een kwaad daglicht stelt of een aanbeveling suggereert, en verkoop of verspreid een foto niet los."),
            'orientations' => [
                ['value' => '', 'label' => __('Elke richting')],
                ['value' => 'landscape', 'label' => __('Liggend')],
                ['value' => 'portrait', 'label' => __('Staand')],
                ['value' => 'square', 'label' => __('Vierkant')],
            ],
        ];
    }

    public function diagnostics(?string $siteId = null): array
    {
        return [
            'enabled' => MediaSourceSettings::enabled($this->key(), $siteId),
            'key' => MediaSourceSettings::hasKey($this->key(), $siteId),
            'max_width' => MediaSourceSettings::stockMaxWidth($siteId),
        ];
    }

    public function search(string $query, int $page = 1, array $options = [], ?string $siteId = null): array
    {
        $query = self::normalizeQuery($query);
        $page = max(1, min(50, $page));
        $orientation = in_array($options['orientation'] ?? null, self::ORIENTATIONS, true) ? $options['orientation'] : null;

        if ($query === '') {
            throw new MediaSourceException(__('Vul een zoekterm in.'), 'invalid');
        }

        $apiKey = $this->apiKey($siteId);
        $maxWidth = MediaSourceSettings::stockMaxWidth($siteId);
        // Niet per gebruiker en niet per sleutel: het antwoord is voor iedereen hetzelfde.
        $cacheKey = 'media-source:' . $this->key() . ':search:' . sha1(json_encode([$query, $page, $orientation, self::PER_PAGE, $maxWidth]));

        $result = Cache::get($cacheKey);

        if (! is_array($result)) {
            // Alleen een echt verzoek naar de aanbieder telt voor de limiet
            // van de gebruiker; een treffer in de cache kost niemand iets.
            if (($options['before_fetch'] ?? null) instanceof \Closure) {
                $options['before_fetch']();
            }

            $this->countProviderRequest($siteId);
            $fetched = $this->fetchPage($query, $page, ['orientation' => $orientation], $apiKey, $maxWidth);
            $result = $this->cleanPage(is_array($fetched) ? $fetched : []);

            Cache::put($cacheKey, $result, self::CACHE_SECONDS);

            // Hooguit PER_PAGE sleutels: cleanPage() kapt de lijst af.
            foreach ($result['items'] as $item) {
                Cache::put($this->photoKey($item['id'], $maxWidth), $item, self::CACHE_SECONDS);
            }
        }

        return [
            // Het downloadadres blijft op de server.
            'items' => array_map(fn (array $item) => array_diff_key($item, ['download' => true]), $result['items']),
            'page' => $page,
            'hasMore' => (bool) ($result['hasMore'] ?? false),
            'total' => isset($result['total']) && is_numeric($result['total']) ? (int) $result['total'] : null,
        ];
    }

    public function import(string $externalId, ?int $folderId = null, ?int $userId = null, string $query = '', ?string $siteId = null): IngestResult
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,40}$/', $externalId)) {
            throw new MediaSourceException(__('Deze foto is niet gevonden.'), 'invalid');
        }

        // Zonder vaste bestandsservers is er niets om elke sprong aan te toetsen: dan geen download.
        if ($this->allowedFileHosts() === []) {
            throw new MediaSourceException(__('Deze foto is niet meer beschikbaar of het adres ervan hoort niet bij :provider.', ['provider' => $this->providerName()]), 'refused');
        }

        if ($existing = $this->existing($externalId, $folderId)) {
            return new IngestResult($existing, true, (string) $existing->getAttribute('checksum'));
        }

        $maxWidth = MediaSourceSettings::stockMaxWidth($siteId);
        $photo = Cache::get($this->photoKey($externalId, $maxWidth));

        if (! is_array($photo)) {
            $apiKey = $this->apiKey($siteId);
            $this->countProviderRequest($siteId);
            $fetched = $this->fetchOne($externalId, $apiKey, $maxWidth);
            $photo = is_array($fetched) ? $this->clean($fetched) : null;
        }

        // Ook wat uit de cache komt opnieuw toetsen: de lijst met hosts is van nu.
        $download = is_array($photo) ? $this->fileUrl($photo['download'] ?? null) : null;

        if ($download === null) {
            throw new MediaSourceException(__('Deze foto is niet meer beschikbaar of het adres ervan hoort niet bij :provider.', ['provider' => $this->providerName()]), 'refused');
        }

        $library = app(MediaLibrary::class);

        $meta = array_filter([
            'provider' => $this->key(),
            'provider_name' => $this->providerName(),
            'provider_home' => $this->providerHome(),
            'link_hosts' => $this->siteHosts(),
            'external_id' => $externalId,
            'author' => $photo['author'] ?? null,
            'author_url' => $photo['author_url'] ?? null,
            'page_url' => $photo['page_url'] ?? null,
            'license' => $this->licenseName(),
            'query' => MediaCredit::text($query, self::QUERY_MAX),
        ], fn ($value) => $value !== null && $value !== '');

        $incoming = $library->download($download, $folderId, 'stock:' . $this->key(), $meta, [
            // Elke sprong moet op een bestandsserver van de aanbieder blijven.
            'allowed_hosts' => $this->allowedFileHosts(),
            'max_redirects' => 2,
            'timeout' => self::DOWNLOAD_DEADLINE,
        ]);

        $result = $library->ingest(new IncomingMedia(
            localPath: $incoming->localPath,
            fileName: $this->key() . '-' . $externalId,
            source: $incoming->source,
            folderId: $incoming->folderId,
            uploadedByUserId: $userId,
            meta: $incoming->meta,
        ));

        // Dezelfde bytes stonden er al (van een URL, een andere aanbieder):
        // de vermelding die de voorwaarden vragen komt er dan bij.
        if ($result->wasDuplicate) {
            MediaCredit::addStockCredit($result->item, $meta);
        }

        return $result;
    }

    /** De totale tijd voor het ophalen van een stockfoto, over alle sprongen samen. */
    public const DOWNLOAD_DEADLINE = 20;

    /** Echte verzoeken naar deze aanbieder per site per uur, standaard ruim onder zijn eigen limiet. */
    abstract public function defaultHourlyLimit(): int;

    public function hourlyLimit(): int
    {
        $value = config('dashed-files.source_limits.provider_hourly.' . $this->key());

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $this->defaultHourlyLimit();
    }

    /**
     * Telt een echt verzoek naar de aanbieder voor deze site, en weigert
     * zodra het uurplafond bereikt is. Eerst tellen, dan toetsen: twee
     * gelijktijdige verzoeken kunnen er niet samen overheen.
     *
     * @throws MediaSourceException
     */
    protected function countProviderRequest(?string $siteId): void
    {
        $key = 'media-source:provider:' . $this->key() . ':' . ($siteId ?? '-');

        if (\Illuminate\Support\Facades\RateLimiter::hit($key, 3600) > $this->hourlyLimit()) {
            throw new MediaSourceException(__('Deze site heeft het afgelopen uur veel bij :provider gezocht. Probeer het later opnieuw; eerder gezochte termen werken wel.', ['provider' => $this->providerName()]), 'rate_limited');
        }
    }

    /**
     * @return array<int, string>
     */
    protected function allowedFileHosts(): array
    {
        return array_values(array_filter(array_map(fn ($host) => is_string($host) ? strtolower(trim($host)) : '', $this->fileHosts())));
    }

    /**
     * Wat een aanbieder terugstuurt, begrensd: hooguit PER_PAGE resultaten,
     * elk opgeschoond, en alleen getallen waar getallen horen.
     *
     * @param  array<string, mixed>  $fetched
     * @return array{items: array<int, array<string, mixed>>, hasMore: bool, total: ?int}
     */
    protected function cleanPage(array $fetched): array
    {
        $raw = is_array($fetched['items'] ?? null) ? array_slice(array_values($fetched['items']), 0, self::PER_PAGE) : [];
        $total = $fetched['total'] ?? null;

        return [
            'items' => array_values(array_filter(array_map(fn ($item) => is_array($item) ? $this->clean($item) : null, $raw))),
            'hasMore' => ($fetched['hasMore'] ?? false) === true,
            'total' => is_numeric($total) && (int) $total >= 0 ? min((int) $total, 1_000_000) : null,
        ];
    }

    public static function normalizeQuery(string $query): string
    {
        $query = (string) preg_replace('/[\p{C}]+/u', ' ', mb_scrub($query, 'UTF-8'));

        return mb_substr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $query))), 0, self::QUERY_MAX);
    }

    /**
     * Een GET naar de aanbieder. Vangt elke fout af en gooit een eigen
     * uitzondering zonder de oorspronkelijke: de foutmelding van de
     * HTTP-client bevat het volledige adres, en daar kan de sleutel in staan.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function get(string $url, array $query = [], array $headers = []): array
    {
        $provider = $this->providerName();

        try {
            $response = Http::withHeaders($headers)->acceptJson()->connectTimeout(5)->timeout(10)->get($url, $query);
            $status = $response->status();
            $body = $response->successful() ? $response->json() : null;
            // Alleen om een ongeldige sleutel te herkennen; de tekst zelf gaat nergens heen.
            $mentionsKey = ! $response->successful() && stripos(mb_substr((string) $response->body(), 0, 300), 'api key') !== false;
        } catch (Throwable) {
            Log::warning('Stockbron niet bereikbaar', ['provider' => $this->key()]);

            throw new MediaSourceException(__(':provider is nu niet bereikbaar. Probeer het later opnieuw.', ['provider' => $provider]), 'unreachable');
        }

        if ($status === 429) {
            throw new MediaSourceException(__('De limiet van :provider is bereikt. Probeer het over een paar minuten opnieuw.', ['provider' => $provider]), 'rate_limited');
        }

        if (in_array($status, [401, 403], true) || $mentionsKey) {
            Log::warning('Stockbron weigert de sleutel', ['provider' => $this->key(), 'status' => $status]);

            throw new MediaSourceException(__('De sleutel van :provider is ongeldig of ingetrokken. Een beheerder kan hem nakijken bij Instellingen, Media.', ['provider' => $provider]), 'invalid_key');
        }

        if (! is_array($body)) {
            Log::warning('Stockbron gaf een onverwacht antwoord', ['provider' => $this->key(), 'status' => $status]);

            throw new MediaSourceException(__('Zoeken bij :provider is niet gelukt. Probeer het later opnieuw.', ['provider' => $provider]), 'failed');
        }

        return $body;
    }

    /** Een adres van een afbeelding: https en op een bestandsserver van de aanbieder, anders null. */
    protected function fileUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > self::LINK_MAX) {
            return null;
        }

        $link = MediaCredit::link($url, $this->fileHosts());
        $host = $link === null ? null : strtolower((string) parse_url($link, PHP_URL_HOST));

        // Exact, geen subdomeinen: dit is waar een bestand vandaan komt.
        return $host !== null && in_array($host, array_map('strtolower', $this->fileHosts()), true) ? $link : null;
    }

    protected function siteLink(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > self::LINK_MAX) {
            return null;
        }

        return MediaCredit::link($url, $this->siteHosts());
    }

    /**
     * Van wat de subklasse uit het antwoord las naar wat bewaard en getoond
     * mag worden. Zonder geldig id, miniatuur of downloadadres valt het
     * resultaat weg.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    protected function clean(array $item): ?array
    {
        $id = is_scalar($item['id'] ?? null) ? (string) $item['id'] : '';
        $thumb = $this->fileUrl($item['thumb'] ?? null);
        $download = $this->fileUrl($item['download'] ?? null);

        if (! preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) || $thumb === null || $download === null) {
            return null;
        }

        $dimension = fn ($value) => is_numeric($value) && (int) $value > 0 && (int) $value < 100000 ? (int) $value : null;

        return [
            'id' => $id,
            'thumb' => $thumb,
            'preview' => $this->fileUrl($item['preview'] ?? null) ?? $thumb,
            'download' => $download,
            'width' => $dimension($item['width'] ?? null),
            'height' => $dimension($item['height'] ?? null),
            'author' => is_string($item['author'] ?? null) ? MediaCredit::text($item['author'], self::NAME_MAX) : null,
            'author_url' => $this->siteLink($item['author_url'] ?? null),
            'page_url' => $this->siteLink($item['page_url'] ?? null),
            'alt' => is_string($item['alt'] ?? null) ? MediaCredit::text($item['alt'], self::ALT_MAX) : null,
            'license' => $this->licenseName(),
        ];
    }

    /**
     * @throws MediaSourceException
     */
    protected function apiKey(?string $siteId): string
    {
        $key = MediaSourceSettings::key($this->key(), $siteId);

        if ($key === null) {
            throw new MediaSourceException(__('Voor :provider is geen sleutel ingesteld.', ['provider' => $this->providerName()]), 'unavailable');
        }

        return $key;
    }

    protected function photoKey(string $externalId, int $maxWidth): string
    {
        return 'media-source:' . $this->key() . ':photo:' . $externalId . ':' . $maxWidth;
    }

    /**
     * Dezelfde foto is al eens geïmporteerd: dat item, zolang het aan
     * dezelfde kant van de privé-grens staat als de doelmap. Wat in de
     * prullenbak ligt telt hier niet; dat vangt de checksum in de inname.
     */
    protected function existing(string $externalId, ?int $folderId): ?MediaItem
    {
        return rescue(function () use ($externalId, $folderId) {
            $private = app(MediaVisibility::class)->isFolderPrivate($folderId);
            $sameSide = fn (MediaItem $item) => MediaVisibility::isPrivate($item) === $private;

            $first = MediaItem::query()
                ->when(BrowseSchema::hasMirror(), fn ($query) => $query->where('source', 'stock:' . $this->key()))
                ->whereHas('media', fn ($media) => $media
                    ->where('collection_name', MediaItem::collectionName())
                    ->where('custom_properties->source', 'stock:' . $this->key())
                    ->where('custom_properties->source_meta->external_id', $externalId))
                ->orderBy('id')
                ->limit(10)
                ->get()
                ->first($sameSide);

            if ($first) {
                return $first;
            }

            // Dezelfde foto als aangevulde vermelding bij een item dat eerst
            // van elders kwam (MediaCredit::addStockCredit). Het id bestaat
            // alleen uit letters, cijfers, - en _ (in LIKE een jokerteken dat
            // hooguit te veel vindt); de echte toets gebeurt daarna in PHP.
            $ref = MediaCredit::stockRef($this->key(), $externalId);

            return MediaItem::query()
                ->whereHas('media', fn ($media) => $media
                    ->where('collection_name', MediaItem::collectionName())
                    ->where('custom_properties', 'like', '%' . $ref . '%'))
                ->orderBy('id')
                ->limit(10)
                ->get()
                ->first(fn (MediaItem $item) => $sameSide($item) && MediaCredit::hasStockPhoto($item, $this->key(), $externalId));
        }, null, false);
    }
}
