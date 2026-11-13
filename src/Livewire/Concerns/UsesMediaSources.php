<?php

namespace Dashed\DashedFiles\Livewire\Concerns;

use Throwable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Renderless;
use Illuminate\Support\Facades\RateLimiter;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Illuminate\Validation\ValidationException;
use Dashed\DashedFiles\Classes\Sources\AiSource;
use Dashed\DashedFiles\Classes\Sources\UrlSource;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Ingest\IngestResult;
use Dashed\DashedFiles\Classes\Sources\MediaSource;
use Dashed\DashedFiles\Classes\Browse\MediaItemPayload;
use Dashed\DashedFiles\Classes\Sources\MediaSourceRegistry;
use Dashed\DashedFiles\Classes\Sources\MediaSourceException;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;
use Dashed\DashedFiles\Classes\Sources\SearchableMediaSource;

/**
 * De externe bronnen in het keuzevenster en op de beheerpagina: een URL,
 * een stockaanbieder, AI. Elke methode vraagt het recht om te uploaden
 * (het enige schrijfrecht dat een keuzevenster doorlaat), zoekt de bron op
 * naam op in het register en weigert wat daar nu niet beschikbaar is. Een
 * fout komt terug als melding in het paneel, nooit als de ruwe fout van een
 * aanbieder.
 */
trait UsesMediaSources
{
    public const SOURCE_URL_PER_MINUTE = 20;

    public const SOURCE_SEARCH_PER_MINUTE = 30;

    public const SOURCE_IMPORT_PER_MINUTE = 30;

    public const SOURCE_AI_PER_MINUTE = 5;

    public const SOURCE_STATUS_PER_MINUTE = 120;

    public const SOURCE_IMPORTS_PER_HOUR = 60;

    public const SOURCE_CONCURRENT_IMPORTS = 2;

    abstract protected function authorizeMedia(string $ability): void;

    /**
     * De bronnen waartoe dit venster beperkt is: null is alles wat
     * beschikbaar is, een lege lijst niets. Het keuzevenster vult dit met
     * wat het veld vroeg (MediaField::sources()).
     *
     * @return array<int, string>|null
     */
    protected function mediaSourceFilter(): ?array
    {
        return null;
    }

    /**
     * @return array{ok: bool, item?: array, duplicate?: bool, restored?: bool, error?: string}
     */
    #[Renderless]
    public function sourceImportUrl(string $url, int|string|null $folderId = null): array
    {
        $this->authorizeMedia('upload');

        return $this->mediaSourceGuarded(UrlSource::KEY, 'url', self::SOURCE_URL_PER_MINUTE, function (MediaSource $source) use ($url, $folderId) {
            if (! $source instanceof UrlSource) {
                return $this->mediaSourceUnavailable();
            }

            return $this->mediaSourceImporting(fn () => $this->mediaSourceImported($source->import($url, $this->mediaSourceFolder($folderId), $this->mediaSourceUser())));
        });
    }

    /**
     * @param  array<string, mixed>  $options  orientation
     * @return array{ok: bool, items?: array, page?: int, hasMore?: bool, total?: ?int, error?: string}
     */
    #[Renderless]
    public function sourceSearch(string $source, string $query, int $page = 1, array $options = []): array
    {
        $this->authorizeMedia('upload');

        // De limiet per gebruiker telt alleen een echt verzoek naar de
        // aanbieder: de bron roept before_fetch pas aan als de cache niets heeft.
        return $this->mediaSourceGuarded($source, 'search', null, function (MediaSource $found) use ($query, $page, $options) {
            if (! $found instanceof SearchableMediaSource) {
                return $this->mediaSourceUnavailable();
            }

            return ['ok' => true] + $found->search($query, $page, [
                'orientation' => is_string($options['orientation'] ?? null) ? $options['orientation'] : null,
                'before_fetch' => fn () => $this->mediaSourceCount('search', self::SOURCE_SEARCH_PER_MINUTE),
            ], MediaSourceRegistry::siteId());
        });
    }

    /**
     * De browser stuurt alleen het id van de aanbieder: het adres van het
     * bestand zoekt de server zelf op.
     *
     * @return array{ok: bool, item?: array, duplicate?: bool, restored?: bool, error?: string}
     */
    #[Renderless]
    public function sourceImport(string $source, string $externalId, string $query = '', int|string|null $folderId = null): array
    {
        $this->authorizeMedia('upload');

        return $this->mediaSourceGuarded($source, 'import', self::SOURCE_IMPORT_PER_MINUTE, function (MediaSource $found) use ($externalId, $query, $folderId) {
            if (! $found instanceof SearchableMediaSource) {
                return $this->mediaSourceUnavailable();
            }

            return $this->mediaSourceImporting(fn () => $this->mediaSourceImported($found->import($externalId, $this->mediaSourceFolder($folderId), $this->mediaSourceUser(), mb_substr($query, 0, 100), MediaSourceRegistry::siteId())));
        });
    }

    /**
     * Zet een AI-afbeelding in de wachtrij en geeft het kenmerk terug
     * waarmee sourceStatus() de stand opvraagt.
     *
     * @return array{ok: bool, request?: string, error?: string}
     */
    #[Renderless]
    public function sourceGenerate(string $prompt, string $ratio = '1:1', ?int $referenceId = null, int|string|null $folderId = null): array
    {
        $this->authorizeMedia('upload');

        return $this->mediaSourceGuarded(AiSource::KEY, 'ai', self::SOURCE_AI_PER_MINUTE, function (MediaSource $source) use ($prompt, $ratio, $referenceId, $folderId) {
            $userId = $this->mediaSourceUser();

            if (! $source instanceof AiSource || $userId === null) {
                return $this->mediaSourceUnavailable();
            }

            // Een keuzevenster zonder allowPrivate() kent de privé-bestanden niet; de bron weigert ze hoe dan ook.
            $reference = is_int($referenceId) && $referenceId > 0 ? $referenceId : null;

            return ['ok' => true, 'request' => $source->request($prompt, $ratio, $reference, $this->mediaSourceFolder($folderId), $userId, MediaSourceRegistry::siteId())];
        });
    }

    /**
     * De stand van een eigen aanvraag. Het kenmerk van een ander geeft
     * hetzelfde antwoord als een kenmerk dat niet bestaat.
     *
     * @return array{ok: bool, status?: string, item?: array, error?: string}
     */
    #[Renderless]
    public function sourceStatus(string $request): array
    {
        $this->authorizeMedia('upload');

        $userId = $this->mediaSourceUser();
        $limiter = 'media-source:status:' . ($userId ?? 0);

        // Eerst tellen, dan toetsen: zo komen gelijktijdige verzoeken er niet samen langs.
        if ($userId === null || RateLimiter::hit($limiter, 60) > self::SOURCE_STATUS_PER_MINUTE) {
            return ['ok' => false, 'error' => __('Even wachten: er wordt te vaak om de stand gevraagd.')];
        }

        // Niet via het register: een aanvraag die al liep mag zijn uitkomst nog laten zien nadat de bron is uitgezet.
        $status = rescue(fn () => (new AiSource())->status($request, $userId), null);

        if ($status === null) {
            return ['ok' => false, 'error' => __('Deze aanvraag is niet gevonden.')];
        }

        if ($status['status'] === 'done') {
            $item = app(MediaLibrary::class)->find((int) $status['item_id']);

            if (! $item) {
                return ['ok' => true, 'status' => 'failed', 'error' => AiSource::failureMessage('failed')];
            }

            return ['ok' => true, 'status' => 'done', 'item' => MediaItemPayload::for($item->loadMissing(BrowseSchema::hasRelations() ? ['media', 'tags'] : ['media']))];
        }

        return ['ok' => true] + $status;
    }

    /**
     * Wat de browser over de bronnen moet weten. Leeg voor wie niet mag
     * uploaden: dan is er geen tabblad en geen knop.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mediaSourcesConfig(): array
    {
        if (! static::mayManage('upload')) {
            return [];
        }

        $siteId = MediaSourceRegistry::siteId();
        $sources = [];

        foreach (app(MediaSourceRegistry::class)->available($siteId, $this->mediaSourceFilter()) as $source) {
            $config = rescue(fn () => $source->browserConfig($siteId), null);

            if (is_array($config)) {
                $sources[] = ['key' => $source->key(), 'kind' => $source->kind(), 'label' => $source->label(), 'icon' => $source->icon()] + $config;
            }
        }

        return $sources;
    }

    /**
     * @return array<string, string>
     */
    public function mediaSourceLabels(): array
    {
        return [
            'importing' => __('Bezig met importeren...'),
            'imported' => __('Geïmporteerd'),
            'existed' => __('Deze afbeelding stond al in de bibliotheek.'),
            'failed' => __('Dat is niet gelukt. Probeer het opnieuw.'),
            'searching' => __('Bezig met zoeken...'),
            'noResults' => __('Niets gevonden. Probeer een andere zoekterm (Engels werkt vaak het best).'),
            'more' => __('Meer laden'),
            'import' => __('Importeren'),
            'photoBy' => __('Foto van :name'),
            'orientation' => __('Richting'),
            'ratio' => __('Verhouding'),
            'prompt' => __('Beschrijving'),
            'reference' => __('Referentie'),
            'useReference' => __('Open afbeelding als referentie gebruiken'),
            'noReference' => __('Geen referentie'),
            'removeReference' => __('Referentie weghalen'),
            'queued' => __('De afbeelding staat in de wachtrij...'),
            'processing' => __('De afbeelding wordt gemaakt...'),
            'generated' => __('De afbeelding is klaar en staat in de bibliotheek.'),
            'close' => __('Sluiten'),
            'originCopied' => __('Vermelding in het bijschrift gezet'),
        ];
    }

    /**
     * Zoekt de bron op, telt het verzoek en maakt van elke afwijzing een
     * melding. Rechten (403) gaan hier niet doorheen: die zijn al getoetst.
     *
     * @return array<string, mixed>
     */
    protected function mediaSourceGuarded(string $key, string $bucket, ?int $perMinute, \Closure $callback): array
    {
        $source = preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $key)
            ? app(MediaSourceRegistry::class)->find($key, MediaSourceRegistry::siteId(), $this->mediaSourceFilter())
            : null;

        if (! $source) {
            return $this->mediaSourceUnavailable();
        }

        try {
            // Null: de bron telt zelf, alleen wanneer het echt iets kost.
            if ($perMinute !== null) {
                $this->mediaSourceCount($bucket, $perMinute);
            }

            return $callback($source);
        } catch (MediaSourceException|MediaRejectedException $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        } catch (ValidationException $exception) {
            return ['ok' => false, 'error' => (string) (collect($exception->errors())->flatten()->first() ?: __('Dat is niet gelukt. Probeer het opnieuw.'))];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Alleen de klasse: de melding van een HTTP-fout kan een adres met een sleutel bevatten.
            report(new \RuntimeException('Externe mediabron mislukt: ' . $exception::class));

            return ['ok' => false, 'error' => __('Dat is niet gelukt. Probeer het opnieuw.')];
        }
    }

    /**
     * Telt een verzoek voor deze gebruiker en weigert boven de grens.
     * Eerst tellen, dan toetsen: RateLimiter::hit() is één atomaire
     * ophoging, dus twee gelijktijdige verzoeken komen er niet samen langs.
     *
     * @throws MediaSourceException
     */
    protected function mediaSourceCount(string $bucket, int $perMinute): void
    {
        if (RateLimiter::hit('media-source:' . $bucket . ':' . ($this->mediaSourceUser() ?? 0), 60) > $perMinute) {
            throw new MediaSourceException(__('Dat waren er veel achter elkaar. Probeer het over een minuut opnieuw.'), 'rate_limited');
        }
    }

    /**
     * Een import van buiten (URL of stock): hooguit een aantal per uur en
     * een paar tegelijk per gebruiker. Het slot heeft een korte wachttijd;
     * daarna een nette melding in plaats van een verzoek dat blijft hangen.
     *
     * @param  \Closure(): array<string, mixed>  $import
     * @return array<string, mixed>
     *
     * @throws MediaSourceException
     */
    protected function mediaSourceImporting(\Closure $import): array
    {
        $user = $this->mediaSourceUser() ?? 0;
        $perHour = config('dashed-files.source_limits.imports_per_hour');
        $perHour = is_numeric($perHour) && (int) $perHour > 0 ? (int) $perHour : self::SOURCE_IMPORTS_PER_HOUR;

        if (RateLimiter::hit('media-source:import-hour:' . $user, 3600) > $perHour) {
            throw new MediaSourceException(__('Je hebt het afgelopen uur al :max afbeeldingen van buiten binnengehaald. Probeer het later opnieuw.', ['max' => $perHour]), 'rate_limited');
        }

        $slots = config('dashed-files.source_limits.concurrent_imports');
        $slots = is_numeric($slots) && (int) $slots > 0 ? min(10, (int) $slots) : self::SOURCE_CONCURRENT_IMPORTS;
        $lock = null;

        // Twee rondes langs de plekken, met een korte pauze ertussen.
        for ($round = 0; $round < 2 && $lock === null; $round++) {
            for ($slot = 0; $slot < $slots && $lock === null; $slot++) {
                // Ruim boven de langste import (deadline plus inname), zodat een vastgelopen verzoek de plek ooit vrijgeeft.
                $candidate = \Illuminate\Support\Facades\Cache::lock('media-source:import-slot:' . $user . ':' . $slot, 120);
                $lock = $candidate->get() ? $candidate : null;
            }

            if ($lock === null && $round === 0) {
                usleep(500_000);
            }
        }

        if ($lock === null) {
            throw new MediaSourceException(__('Er loopt al een import. Wacht tot die klaar is en probeer het dan opnieuw.'), 'busy');
        }

        try {
            return $import();
        } finally {
            rescue(fn () => $lock->release(), null, false);
        }
    }

    /**
     * @return array{ok: false, error: string}
     */
    protected function mediaSourceUnavailable(): array
    {
        return ['ok' => false, 'error' => __('Deze bron is hier niet beschikbaar.')];
    }

    /**
     * @return array{ok: true, item: array, duplicate: bool, restored: bool}
     */
    protected function mediaSourceImported(IngestResult $result): array
    {
        $item = $result->item->loadMissing(BrowseSchema::hasRelations() ? ['media', 'tags'] : ['media']);
        $userId = $this->mediaSourceUser();

        $favorite = $result->wasDuplicate && $userId && BrowseSchema::hasRelations()
            && DB::table(BrowseSchema::FAVORITES)->where('user_id', $userId)->where('media_item_id', $item->getKey())->exists();

        return [
            'ok' => true,
            'item' => MediaItemPayload::for($item, $favorite),
            'duplicate' => $result->wasDuplicate,
            'restored' => $result->wasRestored,
        ];
    }

    protected function mediaSourceFolder(int|string|null $folderId): ?int
    {
        if ($folderId === null || $folderId === '' || $folderId === 'root') {
            return null;
        }

        $id = is_int($folderId) || ctype_digit($folderId) ? (int) $folderId : 0;

        if ($id < 1 || ! app(MediaLibrary::class)->folder($id)) {
            throw ValidationException::withMessages(['folder_id' => __('De doelmap bestaat niet meer.')]);
        }

        return $id;
    }

    protected function mediaSourceUser(): ?int
    {
        return is_numeric($id = auth()->id()) ? (int) $id : null;
    }
}
