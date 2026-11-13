<?php

namespace Dashed\DashedFiles\Classes\Conversions;

use Closure;
use Throwable;
use RuntimeException;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Spatie\MediaLibrary\Conversions\Conversion;
use Dashed\DashedFiles\Classes\MediaConversions;
use Dashed\DashedFiles\Classes\MediaCapabilities;
use Dashed\DashedFiles\Jobs\RegenerateMediaBatch;
use Dashed\DashedFiles\Models\MediaConversionRun;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Spatie\MediaLibrary\Support\TemporaryDirectory;
use Dashed\DashedFiles\Classes\ConversionDefinition;
use Dashed\DashedFiles\Classes\MediaPickerFileCache;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Dashed\DashedFiles\Jobs\FlushMediaDependentCaches;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;
use Spatie\MediaLibrary\Conversions\Actions\PerformConversionAction;

/**
 * De pomp: genereert formaten in kleine slagen, zodat de wachtrij (die
 * gedeeld wordt met de ordermails van de webshop) nooit volloopt. Een run
 * is een rij in `dashed__media_conversion_runs` met een cursor; één taak
 * (RegenerateMediaBatch) verwerkt binnen een tijdsbudget een paar formaten
 * en zet zichzelf opnieuw klaar zolang er werk is.
 *
 * Drie dingen houden één zwaar of kapot bestand ervan af de pomp vast te
 * zetten: de klok wordt per formaat getoetst (een item kan over meerdere
 * slagen lopen), een item dat twee keer mislukt of afbreekt wordt voortaan
 * overgeslagen, en boven een megapixelgrens begint de pomp er niet aan.
 */
class ConversionPump
{
    public const MISSING = 'missing';

    public const FORCE = 'force';

    public const FOCAL = 'focal';

    public const AVIF = 'avif';

    public const CLEANUP = 'cleanup';

    public const TYPES = [self::MISSING, self::FORCE, self::FOCAL, self::AVIF, self::CLEANUP];

    // Bij deze soorten voegt een tweede aanvraag zich bij de lopende run.
    public const JOINABLE = [self::MISSING, self::FOCAL];

    public const BUDGET_SECONDS = 25.0;

    public const CHUNK = 50;

    public const MAX_IDS = 5000;

    // Na zoveel mislukte of afgebroken pogingen slaat elke run het bestand over.
    public const MAX_ATTEMPTS = 2;

    // Een keten waarvan de hartslag ouder is dan dit is stilgevallen: de
    // minuuttaak zet dan een nieuwe slag klaar.
    public const HEARTBEAT_STALE_SECONDS = 180;

    // Een run die zo lang "bezig" is zonder voortgang heeft zijn werker verloren.
    public const STALE_MINUTES = 15;

    public const ATTEMPTS_PROPERTY = 'pump_attempts';

    private const HAS_MISSING_KEY = 'media-conversions-has-missing';

    private const HOOK = 'dashed-files.conversion-requests-hooked';

    /** @var Closure|null de klok in seconden, voor tests */
    public static ?Closure $clock = null;

    /** Het item dat de pomp op dit moment verwerkt: de observers legen de cache dan niet per formaat. */
    private static ?int $handling = null;

    /** @var array<int, true> items waarvan het bezoekerspad een formaat miste, tot het einde van het verzoek */
    private static array $requested = [];

    public static function isHandling(int|string|null $itemId): bool
    {
        return self::$handling !== null && (int) $itemId === self::$handling;
    }

    /**
     * Vlak voor een mediarij wordt opgeslagen terwijl de pomp hem verwerkt:
     * elke schrijver zet de hele kolom `generated_conversions` terug, dus
     * een formaat dat een ander proces intussen bijschreef zou verdwijnen.
     * Hier wordt de rij vers gelezen en alleen wat deze schrijver zelf
     * veranderde eroverheen gelegd.
     */
    public static function mergeGeneratedConversions(Media $media): void
    {
        if (! self::isHandling($media->model_id) || ! $media->exists || ! $media->isDirty('generated_conversions')) {
            return;
        }

        rescue(function () use ($media) {
            $stored = json_decode((string) DB::table('media')->where('id', $media->getKey())->value('generated_conversions'), true);

            if (! is_array($stored)) {
                return;
            }

            $current = (array) $media->generated_conversions;
            $original = json_decode((string) $media->getRawOriginal('generated_conversions'), true);
            $original = is_array($original) ? $original : [];

            foreach ($current as $name => $value) {
                if (! array_key_exists($name, $original) || $original[$name] !== $value) {
                    $stored[$name] = $value;
                }
            }

            $media->generated_conversions = $stored;
        }, report: false);
    }

    /** Of de standaardwachtrij taken in het verzoek zelf uitvoert. */
    public static function queueIsSync(): bool
    {
        return config('queue.connections.' . config('queue.default') . '.driver', config('queue.default')) === 'sync';
    }

    /**
     * Start een run, of voegt de aanvraag bij een lopende run van dezelfde
     * soort. Er is hooguit één actieve run per soort.
     *
     * @param  array{ids?: array<int, int|string>, names?: array<int, string>, all?: bool}  $filters
     * @param  bool  $block  false op het pad van een bezoeker: dan wacht de aanvraag nooit op een slot
     *
     * @throws MediaRejectedException
     */
    public function start(string $type, array $filters = [], ?int $userId = null, bool $block = true): MediaConversionRun
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new MediaRejectedException(__('Onbekende soort run: :type.', ['type' => $type]));
        }

        if (! BrowseSchema::hasRuns()) {
            throw new MediaRejectedException(__('De tabel voor het genereren van formaten ontbreekt nog. Draai eerst de migraties.'));
        }

        // Het focuspunt, de presets en AVIF bestaan alleen op de eigen motor:
        // op de oude motor registreert het pakket de formaten.
        if (! MediaLibrary::usesOwnEngine()) {
            throw new MediaRejectedException(__('Formaten genereren via de pomp werkt alleen op de eigen mediabibliotheek.'));
        }

        if ($type === self::AVIF) {
            if (! MediaLibrary::avifEnabled()) {
                throw new MediaRejectedException(__('AVIF staat uit. Zet eerst de instelling aan.'));
            }

            if (! MediaCapabilities::avif()) {
                throw new MediaRejectedException(__('Deze server kan geen AVIF schrijven (beelddriver: :driver).', ['driver' => MediaCapabilities::imageDriver()]));
            }
        }

        $filters = $this->cleanFilters($type, $filters);
        $lock = Cache::lock('media-conversion-run-start-' . $type, 10);

        try {
            if ($block) {
                $lock->block(5);
            } elseif (! $lock->get()) {
                throw new MediaRejectedException(__('Er wordt op dit moment al een run gestart. Probeer het zo opnieuw.'));
            }
        } catch (LockTimeoutException) {
            throw new MediaRejectedException(__('Er wordt op dit moment al een run gestart. Probeer het zo opnieuw.'));
        }

        $dispatch = true;

        try {
            $active = $this->active($type);

            if ($active) {
                if (! in_array($type, self::JOINABLE, true)) {
                    throw new MediaRejectedException(__('Er loopt al een run van deze soort (:done van :total). Wacht tot die klaar is of annuleer hem.', ['done' => $active->done, 'total' => $active->total]));
                }

                $this->join($active, $filters);
                $run = $active->refresh();
                // De lopende keten pakt de aanvraag zelf op; alleen een keten
                // die stilligt krijgt een nieuwe slag. Zo staat er nooit een
                // tweede taak van dezelfde run in de wachtrij.
                $dispatch = self::queueIsSync() || $this->chainIsStale($run, 60);
            } else {
                $run = new MediaConversionRun([
                    'type' => $type,
                    'status' => MediaConversionRun::PENDING,
                    'filters' => $filters,
                    'cursor' => 0,
                    'done' => 0,
                    'failed' => 0,
                    'user_id' => $userId,
                ]);
                $run->total = $this->candidates($run)->count();
                $run->save();
            }
        } finally {
            rescue(fn () => $lock->release(), report: false);
        }

        if ($dispatch) {
            $this->dispatch($run);
        }

        return $run->refresh();
    }

    /**
     * Vanaf het pad van een bezoeker: een formaat van dit item ontbreekt.
     * Alleen onthouden; aan het einde van het verzoek gaat alles in één
     * keer naar de pomp, hoeveel afbeeldingen de pagina ook had.
     */
    public function requestMissing(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        self::$requested[$itemId] = true;

        if (! app()->bound(self::HOOK)) {
            app()->instance(self::HOOK, true);
            app()->terminating(fn () => app(self::class)->flushRequested());
        }
    }

    /**
     * Schrijft de verzamelde aanvragen weg: één run (of één bijvoeging) voor
     * alle items samen, zonder blokkerend slot en nooit met een fout terug.
     * Per item geldt een oplopende pauze, zoals bij het losse genereren op
     * het leespad: een bestand dat nooit lukt kost zo niet bij elke verlopen
     * cache opnieuw een aanvraag.
     *
     * @return array<int, int> de items die echt zijn aangevraagd
     */
    public function flushRequested(): array
    {
        $ids = array_keys(self::$requested);
        self::$requested = [];

        if ($ids === []) {
            return [];
        }

        return (array) rescue(function () use ($ids) {
            if (! BrowseSchema::hasRuns() || ! MediaLibrary::usesOwnEngine()) {
                return [];
            }

            $due = [];

            foreach ($ids as $id) {
                $attempts = (int) Cache::get(self::requestAttemptsKey($id), 0);
                $wait = (int) min(300 * (2 ** min($attempts, 16)), 86400);

                if (Cache::add(self::requestLockKey($id), true, $wait)) {
                    Cache::put(self::requestAttemptsKey($id), $attempts + 1, 86400 * 7);
                    $due[] = $id;
                }
            }

            if ($due === []) {
                return [];
            }

            try {
                $this->start(self::MISSING, ['ids' => $due], null, block: false);
            } catch (MediaRejectedException) {
                // Het slot was bezet: de pauze vervalt, een volgend verzoek vraagt het opnieuw.
                foreach ($due as $id) {
                    Cache::forget(self::requestLockKey($id));
                }

                return [];
            }

            return $due;
        }, [], false);
    }

    public static function forgetRequested(): void
    {
        self::$requested = [];
    }

    /** Of er al een run loopt die de ontbrekende formaten van dit item maakt. */
    public function covers(int $itemId): bool
    {
        if (! BrowseSchema::hasRuns()) {
            return false;
        }

        return (bool) rescue(fn () => MediaConversionRun::query()
            ->whereIn('status', MediaConversionRun::ACTIVE)
            ->whereIn('type', [self::MISSING, self::FOCAL])
            ->get(['id', 'type', 'filters'])
            ->contains(fn (MediaConversionRun $run) => $run->itemIds() === [] || in_array($itemId, $run->itemIds(), true)), false, false);
    }

    public function cancel(MediaConversionRun|int $run): ?MediaConversionRun
    {
        $run = $run instanceof MediaConversionRun ? $run : MediaConversionRun::query()->find($run);

        if (! $run || ! $run->isActive()) {
            return $run;
        }

        MediaConversionRun::query()->whereKey($run->getKey())->whereIn('status', MediaConversionRun::ACTIVE)->update([
            'status' => MediaConversionRun::CANCELLED,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        return $run->refresh();
    }

    /**
     * Hervat een mislukte of geannuleerde run vanaf zijn cursor.
     *
     * @throws MediaRejectedException
     */
    public function resume(MediaConversionRun|int $run): MediaConversionRun
    {
        $run = $run instanceof MediaConversionRun ? $run->refresh() : MediaConversionRun::query()->find($run);

        if (! $run) {
            throw new MediaRejectedException(__('Deze run bestaat niet meer.'));
        }

        if ($run->status === MediaConversionRun::DONE) {
            throw new MediaRejectedException(__('Deze run is al klaar.'));
        }

        if (! $run->isActive()) {
            $other = $this->active($run->type);

            if ($other && $other->getKey() !== $run->getKey()) {
                throw new MediaRejectedException(__('Er loopt al een andere run van deze soort.'));
            }

            MediaConversionRun::query()->whereKey($run->getKey())->update([
                'status' => MediaConversionRun::PENDING,
                'finished_at' => null,
                'updated_at' => now(),
            ]);
            $run->refresh();
        }

        $this->dispatch($run);

        return $run->refresh();
    }

    public function markFailed(int $runId, ?string $reason): void
    {
        if (! BrowseSchema::hasRuns()) {
            return;
        }

        $updated = MediaConversionRun::query()->whereKey($runId)->whereIn('status', MediaConversionRun::ACTIVE)->update([
            'status' => MediaConversionRun::FAILED,
            'last_error' => mb_substr((string) $reason, 0, 2000),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        if ($updated) {
            Log::warning('Media conversion run #' . $runId . ' failed: ' . mb_substr((string) $reason, 0, 500));
        }
    }

    /** De actieve run van een soort, of van welke soort ook. */
    public function active(?string $type = null): ?MediaConversionRun
    {
        return $this->activeRuns($type)->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, MediaConversionRun>
     */
    public function activeRuns(?string $type = null): \Illuminate\Database\Eloquent\Collection
    {
        if (! BrowseSchema::hasRuns()) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        // Een run die al een kwartier "bezig" is zonder voortgang heeft zijn
        // werker verloren en geen minuuttaak die hem oppakt: hij wordt
        // mislukt en is daarna te hervatten.
        MediaConversionRun::query()
            ->where('status', MediaConversionRun::RUNNING)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => MediaConversionRun::FAILED, 'last_error' => 'No progress for ' . self::STALE_MINUTES . ' minutes: the worker stopped.', 'finished_at' => now()]);

        return MediaConversionRun::query()
            ->whereIn('status', MediaConversionRun::ACTIVE)
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->orderBy('id')
            ->get();
    }

    /**
     * Zet één slag klaar. Elke slag krijgt een eigen kenmerk; alleen de taak
     * met het kenmerk dat op de run staat mag hem verder zetten, dus een
     * oudere keten die nog in de wachtrij stond stopt vanzelf.
     */
    public function dispatch(MediaConversionRun $run, int $delay = 0): void
    {
        $token = Str::random(24);

        MediaConversionRun::query()->whereKey($run->getKey())->update(['owner' => $token]);

        $queue = $run->type === self::AVIF
            ? (config('dashed-files.conversions.avif_queue') ?: config('dashed-files.ingest.queue'))
            : config('dashed-files.ingest.queue');

        $job = (new RegenerateMediaBatch((int) $run->getKey(), $token))->onQueue($queue);

        if ($delay > 0 && ! self::queueIsSync()) {
            $job->delay($delay);
        }

        dispatch($job);
    }

    /**
     * De minuuttaak. Op een synchrone wachtrij is dit wat een run verder
     * zet: één slag per aanroep. Op een echte wachtrij is het het vangnet
     * voor een keten die stilviel (de werker stierf midden in een slag).
     *
     * @return int het aantal runs dat een slag kreeg
     */
    public function tick(): int
    {
        $count = 0;

        foreach ($this->activeRuns() as $run) {
            if (self::queueIsSync() || $this->chainIsStale($run, self::HEARTBEAT_STALE_SECONDS)) {
                $this->dispatch($run);
                $count++;
            }
        }

        return $count;
    }

    public function hasActiveRun(): bool
    {
        return BrowseSchema::hasRuns() && (bool) rescue(fn () => MediaConversionRun::query()->whereIn('status', MediaConversionRun::ACTIVE)->exists(), false, false);
    }

    /**
     * Eén slag: verwerkt items vanaf de cursor tot het tijdsbudget op is.
     *
     * @param  string|null  $token  het kenmerk van de taak; wijkt het af van de run, dan is dit een oude keten
     * @return bool of er nog werk over is (dan volgt er een volgende slag)
     */
    public function process(MediaConversionRun $run, ?float $budget = null, ?string $token = null): bool
    {
        $budget ??= self::BUDGET_SECONDS;
        $started = $this->now();
        $id = $run->getKey();

        $state = MediaConversionRun::query()->whereKey($id)->first();

        if (! $state || ! $state->isActive() || ($token !== null && $state->owner !== null && $state->owner !== $token)) {
            return false;
        }

        MediaConversionRun::query()->whereKey($id)->update([
            'status' => MediaConversionRun::RUNNING,
            'started_at' => $state->started_at ?? now(),
            'heartbeat_at' => now(),
            'updated_at' => now(),
        ]);

        $cursor = (int) $state->cursor;
        $counters = ['done' => 0, 'failed' => 0, 'skipped' => 0];
        $problems = [];

        $flush = function () use ($id, &$cursor, &$counters, &$problems) {
            $update = [
                'cursor' => $cursor,
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ];

            foreach ($counters as $column => $amount) {
                if ($amount > 0) {
                    $update[$column] = DB::raw($column . ' + ' . (int) $amount);
                }
            }

            if ($problems !== []) {
                // De laatste meldingen bij elkaar: één regel per item.
                $previous = (string) MediaConversionRun::query()->whereKey($id)->value('last_error');
                $lines = array_slice(array_filter(array_merge(explode("\n", $previous), $problems)), -10);
                $update['last_error'] = mb_substr(implode("\n", $lines), -2000);
            }

            MediaConversionRun::query()->whereKey($id)->update($update);
            $counters = ['done' => 0, 'failed' => 0, 'skipped' => 0];
            $problems = [];
        };

        $outOfTime = fn (): bool => $this->now() - $started >= $budget;

        while (true) {
            // Vers gelezen: een tweede aanvraag kan zich intussen hebben bijgevoegd.
            $state = MediaConversionRun::query()->whereKey($id)->first();

            if (! $state || $state->status !== MediaConversionRun::RUNNING || ($token !== null && $state->owner !== $token)) {
                $flush();

                return false;
            }

            $rows = $this->candidates($state)->where('items.id', '>', $cursor)->orderBy('items.id')->limit(self::CHUNK)->get();

            if ($rows->isEmpty()) {
                $flush();

                if ($this->rewind($id)) {
                    $cursor = 0;

                    continue;
                }

                $this->finish($state);

                return false;
            }

            foreach ($rows as $row) {
                $names = $this->namesFor($state, $row);
                $skip = $names === [] ? null : $this->skipReason($row, $state);

                if ($skip !== null) {
                    if (str_contains($skip, 'memory_limit')) {
                        $this->warnMemorySkip((int) $row->id, $skip);
                    }

                    $counters['skipped']++;
                    $problems[] = '#' . $row->id . ': skipped, ' . $skip;
                    $names = [];
                }

                $interrupted = false;

                if ($names !== []) {
                    // Annuleren werkt per item: het lopende item maakt hij nog af.
                    if (MediaConversionRun::query()->whereKey($id)->value('status') !== MediaConversionRun::RUNNING) {
                        $flush();

                        return false;
                    }

                    $partial = (array) ($state->filters['partial'] ?? []);
                    $alreadyDone = (int) ($partial['id'] ?? 0) === (int) $row->id ? (array) ($partial['done'] ?? []) : [];
                    $names = array_values(array_diff($names, $alreadyDone));

                    $result = $this->handle($state, $row, $names, $outOfTime);

                    if ($result['problem'] !== null) {
                        $counters['failed']++;
                        $problems[] = mb_substr('#' . $row->id . ': ' . $result['problem'], 0, 400);
                    }

                    $interrupted = $result['interrupted'];

                    // Een `force` maakt formaten die al bestaan opnieuw: wat
                    // er van dit item al gedaan is moet de volgende slag weten.
                    if ($state->type === self::FORCE) {
                        $this->setPartial($id, $interrupted ? ['id' => (int) $row->id, 'done' => array_values(array_merge($alreadyDone, $result['done']))] : null);
                    }
                }

                if ($interrupted) {
                    // De cursor blijft vóór dit item: de volgende slag gaat verder bij het volgende formaat.
                    $flush();

                    return true;
                }

                $cursor = (int) $row->id;
                $counters['done']++;

                if ($names !== []) {
                    $flush();

                    // AVIF is in dit proces uitgezet: de rest van de run heeft geen zin.
                    if ($state->type === self::AVIF && ! MediaCapabilities::avif()) {
                        $this->markFailed((int) $id, MediaCapabilities::avifDisabledBecause() ?? 'AVIF is not available.');

                        return false;
                    }
                }

                if ($outOfTime()) {
                    $flush();

                    return true;
                }
            }

            $flush();
        }
    }

    /**
     * Of er ergens een verwacht formaat ontbreekt dat de pomp ook echt gaat
     * maken. Stopt bij het eerste; vijf minuten onthouden, want de
     * beheerpagina vraagt het bij elke start. Een bestand dat is opgegeven
     * (te groot, twee keer mislukt) telt niet mee: anders zou er elke nacht
     * een volle run voor niets draaien.
     */
    public function hasMissing(bool $fresh = false): bool
    {
        if (! BrowseSchema::hasRuns() || ! MediaLibrary::usesOwnEngine()) {
            return false;
        }

        if ($fresh) {
            Cache::forget(self::HAS_MISSING_KEY);
        }

        return (bool) Cache::remember(self::HAS_MISSING_KEY, 300, function () {
            foreach ($this->candidates(null)->orderBy('items.id')->lazy(500) as $row) {
                if ($this->missingNames($row) !== []) {
                    return true;
                }
            }

            return false;
        });
    }

    /** Of er AVIF-varianten ontbreken van formaten die uitgeleverd worden. Voor de nachtrun. */
    public function hasMissingAvif(): bool
    {
        if (! BrowseSchema::hasRuns() || ! MediaLibrary::usesOwnEngine() || ! MediaLibrary::avifEnabled() || ! MediaCapabilities::avif()) {
            return false;
        }

        $run = new MediaConversionRun(['type' => self::AVIF, 'filters' => []]);

        foreach ($this->candidates($run)->orderBy('items.id')->lazy(500) as $row) {
            if ($this->namesFor($run, $row) !== [] && $this->skipReason($row, $run) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wat een run zou doen, zonder hem te starten: het aantal items met werk
     * en het aantal bestanden.
     *
     * @return array{items: int, files: int, skipped: int}
     */
    public function estimate(string $type, array $filters = []): array
    {
        $run = new MediaConversionRun(['type' => $type, 'filters' => $this->cleanFilters($type, $filters)]);
        $estimate = ['items' => 0, 'files' => 0, 'skipped' => 0];

        foreach ($this->candidates($run)->orderBy('items.id')->lazy(500) as $row) {
            $names = $this->namesFor($run, $row);

            if ($names === []) {
                continue;
            }

            if ($this->skipReason($row, $run) !== null) {
                $estimate['skipped']++;

                continue;
            }

            $estimate['items']++;
            $estimate['files'] += count($names);
        }

        return $estimate;
    }

    /**
     * De bestanden die de pomp heeft opgegeven: te vaak mislukt.
     *
     * @return array<int, int> item-id's
     */
    public function givenUp(int $limit = 50): array
    {
        return rescue(fn () => DB::table('media')
            ->whereIn('model_type', [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS])
            ->where('collection_name', MediaItem::COLLECTION)
            ->where('custom_properties->' . self::ATTEMPTS_PROPERTY, '>=', self::MAX_ATTEMPTS)
            ->orderBy('model_id')
            ->limit($limit)
            ->pluck('model_id')
            ->map(fn ($id) => (int) $id)
            ->all(), [], false);
    }

    /**
     * Het aantal formaten dat een thema ooit vroeg (de kolom `conversions`)
     * maar dat niet gegenereerd is. De meting van `dashed:media:doctor`; de
     * miniatuur en een niet-gevraagd `medium` tellen hier niet mee.
     */
    public function countMissingRequested(): int
    {
        $missing = 0;

        foreach ($this->candidates(null)->whereNotNull('items.conversions')->orderBy('items.id')->lazy(500) as $row) {
            $generated = $this->generated($row);
            $requested = json_decode($row->conversions ?: '[]', true);
            $names = [];

            foreach (is_array($requested) ? $requested : [] as $key) {
                $definition = is_string($key) || is_array($key) ? ConversionDefinition::fromLegacy($key) : null;

                if ($definition) {
                    $names[$definition->withFocal($this->number($row->focal_x ?? null), $this->number($row->focal_y ?? null))->name] = true;
                }
            }

            foreach (array_keys($names) as $name) {
                if (empty($generated[$name])) {
                    $missing++;
                }
            }
        }

        return $missing;
    }

    /**
     * De namen die dit item hoort te hebben en niet heeft. Leeg voor een
     * bestand dat de pomp heeft opgegeven of niet aankan.
     *
     * @return array<int, string>
     */
    public function missingNames(object $row, bool $onlyCropping = false, bool $evenIfGivenUp = false): array
    {
        if (! $evenIfGivenUp && $this->skipReason($row, null) !== null) {
            return [];
        }

        $generated = $this->generated($row);
        $names = [];

        foreach (MediaConversions::libraryDefinitions($row->conversions, $this->number($row->focal_x ?? null), $this->number($row->focal_y ?? null)) as $name => $definition) {
            if (empty($generated[$name]) && (! $onlyCropping || $definition->isCropping())) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Waarom de pomp dit bestand niet aanraakt, of null. Een `force` met
     * dit item uitdrukkelijk erin vraagt het opnieuw: dan telt de
     * pogingenteller niet. De megapixelgrens geldt altijd.
     */
    public function skipReason(object $row, ?MediaConversionRun $run): ?string
    {
        $properties = $this->properties($row);
        $limit = (float) config('dashed-files.conversions.max_megapixels', 60);
        $width = $this->number($properties['original_width'] ?? ($row->width ?? null));
        $height = $this->number($properties['original_height'] ?? ($row->height ?? null));

        if ($limit > 0 && $width && $height && ($width * $height) / 1_000_000 > $limit) {
            return sprintf('%d megapixels is above the limit of %d for generating formats', (int) round($width * $height / 1_000_000), (int) $limit);
        }

        if ($reason = self::memoryShortfall($width, $height, (int) $row->id)) {
            return $reason;
        }

        $asked = $run && $run->type === self::FORCE && in_array((int) $row->id, $run->itemIds(), true);

        if (! $asked && (int) ($properties[self::ATTEMPTS_PROPERTY] ?? 0) >= self::MAX_ATTEMPTS) {
            return 'failed ' . self::MAX_ATTEMPTS . ' times before; ask again with a force run for this item';
        }

        return null;
    }

    /** Alleen voor tests: een nagebootste geheugenlimiet in bytes (-1 = onbeperkt) en gebruik. */
    public static ?int $memoryLimitOverride = null;

    public static ?int $memoryUsageOverride = null;

    /** Bytes per pixel die gd tijdens het schalen nodig heeft (bron en doel, met overhead). */
    public const GD_BYTES_PER_PIXEL = 6;

    /** Marge voor het proces zelf, in bytes. */
    public const MEMORY_MARGIN = 32 * 1024 * 1024;

    /**
     * Past dit origineel niet in het geheugen dat gd nog heeft? Dan de reden
     * (met de uitweg), anders null. Alleen voor gd: imagick werkt buiten het
     * geheugen van PHP en wordt niet geblokkeerd. Een grens van -1 is
     * onbeperkt. Zonder deze toets geeft gd een fatale fout die de hele
     * werker meeneemt, en dat is geen uitzondering die de pomp kan vangen.
     */
    public static function memoryShortfall(?int $width, ?int $height, ?int $itemId = null): ?string
    {
        if (! $width || ! $height || MediaCapabilities::imageDriver() !== 'gd') {
            return null;
        }

        $limit = self::$memoryLimitOverride ?? self::parseMemoryLimit((string) ini_get('memory_limit'));

        if ($limit < 0) {
            return null;
        }

        $needed = $width * $height * self::GD_BYTES_PER_PIXEL + self::MEMORY_MARGIN;
        $available = $limit - (self::$memoryUsageOverride ?? memory_get_usage(true));

        if ($needed <= $available) {
            return null;
        }

        return sprintf(
            'needs about %d MB of memory for %dx%d pixels and only %d MB is left under memory_limit; run php -d memory_limit=1G artisan dashed:media-conversions force --ids=%s',
            (int) ceil($needed / 1048576),
            $width,
            $height,
            (int) max(0, floor($available / 1048576)),
            $itemId ?? '<id>',
        );
    }

    /** Bytes uit de notatie van php.ini (`256M`, `1G`, `-1`). */
    protected static function parseMemoryLimit(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** De item-id's die om geheugen worden overgeslagen en waar iets voor ontbreekt. De meting van de doctor. */
    public function skippedForMemory(): array
    {
        $ids = [];

        foreach ($this->candidates(null)->orderBy('items.id')->lazy(500) as $row) {
            $properties = $this->properties($row);

            if (self::memoryShortfall($this->number($properties['original_width'] ?? null), $this->number($properties['original_height'] ?? null), (int) $row->id) !== null
                && $this->missingNames($row, false, true) !== []) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }

    /** Eén waarschuwing per item per dag, niet een per run of per aanroep. */
    public function warnMemorySkip(int $itemId, string $reason): void
    {
        if (rescue(fn () => Cache::add('media-conversion-memory-skip-' . $itemId, true, 86400), true, false)) {
            Log::warning('Media item #' . $itemId . ' is skipped for generating formats: ' . $reason);
        }
    }

    /**
     * Maakt één formaat van een mediarij, synchroon, van de lokale kopie
     * van het origineel. Rechtstreeks via de actie van Spatie: die weg zet
     * zelf geen taak klaar, wat de registratie van het formaat ook zegt.
     */
    protected function performOne(Media $media, string $name, string $original): void
    {
        $conversion = ConversionCollection::createForMedia($media)->first(fn (Conversion $conversion) => $conversion->getName() === $name);

        if (! $conversion) {
            throw new RuntimeException("The format [{$name}] is not registered for this file.");
        }

        // Eén decodering voor alle formaten van dit item (schakelaar
        // conversions.single_decode); wat de generator niet kan, gaat via Spatie.
        if (SingleDecodeGenerator::enabled() && $this->singleDecode($media, $original)->generate($media, $conversion)) {
            return;
        }

        (new PerformConversionAction())->execute($conversion, $media, $original);
    }

    private ?SingleDecodeGenerator $singleDecode = null;

    private ?string $singleDecodeFor = null;

    private function singleDecode(Media $media, string $original): SingleDecodeGenerator
    {
        if ($this->singleDecode && $this->singleDecodeFor === $original) {
            return $this->singleDecode;
        }

        $this->releaseSingleDecode();

        $sets = ConversionCollection::createForMedia($media)->map(fn (Conversion $conversion) => $conversion->getManipulations()->toArray())->all();

        $this->singleDecodeFor = $original;

        return $this->singleDecode = new SingleDecodeGenerator($original, MediaCapabilities::imageDriver(), array_values($sets));
    }

    private function releaseSingleDecode(): void
    {
        $this->singleDecode?->release();
        $this->singleDecode = null;
        $this->singleDecodeFor = null;
    }

    /**
     * Verwerkt één item: formaat voor formaat, met de klok ertussen.
     *
     * @param  array<int, string>  $names
     * @param  Closure(): bool  $outOfTime
     * @return array{done: array<int, string>, problem: ?string, interrupted: bool}
     */
    protected function handle(MediaConversionRun $run, object $row, array $names, Closure $outOfTime): array
    {
        $result = ['done' => [], 'problem' => null, 'interrupted' => false];

        if ($names === []) {
            return $result;
        }

        // De registratie leest de kolom `conversions` en het focuspunt per
        // item uit een geheugen per proces; hier altijd vers.
        MediaPickerFileCache::forgetConversions($row->id);

        $media = Media::query()->find($row->media_id);

        if (! $media) {
            $result['problem'] = 'The media row is gone.';

            return $result;
        }

        if ($run->type === self::CLEANUP) {
            try {
                $this->cleanUp($media, (int) $row->id);
            } catch (Throwable $exception) {
                $result['problem'] = $exception->getMessage() ?: get_class($exception);
            }

            return $result;
        }

        // Eén schrijver tegelijk op `generated_conversions` van deze rij:
        // elke schrijver zet de hele JSON-kolom terug.
        $lock = Cache::lock(self::mediaLockKey($media->getKey()), 120);

        if (! rescue(fn () => $lock->block(10), false, false)) {
            $result['interrupted'] = true;

            return $result;
        }

        // De teller gaat vóór het werk omhoog: sterft de werker halverwege
        // (time-out, geheugen), dan staat de poging er al.
        $before = (int) $media->getCustomProperty(self::ATTEMPTS_PROPERTY, 0);
        $this->setAttempts($media, $before + 1);

        $directory = null;
        self::$handling = (int) $row->id;

        try {
            $directory = TemporaryDirectory::create();
            $original = app(Filesystem::class)->copyFromMediaLibrary($media, $directory->path(Str::random(32) . '.' . $media->extension));

            if (! is_file($original) || filesize($original) === 0) {
                throw new RuntimeException('The file is gone from the disk or empty.');
            }

            // Van groot naar klein: een verkleind formaat dient als tussenstap voor het volgende.
            if (SingleDecodeGenerator::enabled() && $run->type !== self::AVIF) {
                $names = SingleDecodeGenerator::order($media, $names);
            }

            $work = function () use ($media, $names, $original, $outOfTime, $run, &$result) {
                foreach ($names as $index => $name) {
                    // Vers: een andere schrijver kan intussen een formaat hebben bijgeschreven.
                    $media->refresh();
                    $this->performOne($media, $name, $original);
                    $media->refresh();

                    $problem = $run->type === self::AVIF ? $this->verifyAvif($media, $name) : null;
                    $problem ??= $media->hasGeneratedConversion($name) ? null : 'Not generated: ' . $name;

                    if ($problem !== null) {
                        throw new RuntimeException($problem);
                    }

                    $result['done'][] = $name;

                    if ($index < count($names) - 1 && $outOfTime()) {
                        $result['interrupted'] = true;

                        return;
                    }
                }
            };

            $run->type === self::AVIF ? MediaConversions::whileGeneratingAvif($work) : $work();

            // Klaar, of netjes onderbroken door de klok: dat is geen mislukte poging.
            $this->setAttempts($media, $result['interrupted'] ? $before : 0);

            if (! $result['interrupted']) {
                Cache::forget(self::requestLockKey((int) $row->id));
                Cache::forget(self::requestAttemptsKey((int) $row->id));
            }
        } catch (Throwable $exception) {
            // Geen melding per item naar de foutenlogger: de run verzamelt ze.
            $result['problem'] = $exception->getMessage() ?: get_class($exception);
        } finally {
            self::$handling = null;
            $this->releaseSingleDecode();
            rescue(fn () => $directory?->delete(), report: false);
            rescue(fn () => $lock->release(), report: false);
        }

        // Eén keer per item, niet per formaat: de observers zwegen zolang het item in behandeling was.
        if ($result['done'] !== []) {
            rescue(fn () => app(MediaLibrary::class)->invalidate((int) $row->id, 'conversions-generated'), report: false);
        }

        return $result;
    }

    protected function setAttempts(Media $media, int $attempts): void
    {
        rescue(function () use ($media, $attempts) {
            // Vers en stil: alleen deze ene eigenschap, zonder de observers.
            $fresh = Media::query()->find($media->getKey());

            if (! $fresh) {
                return;
            }

            $attempts > 0 ? $fresh->setCustomProperty(self::ATTEMPTS_PROPERTY, $attempts) : $fresh->forgetCustomProperty(self::ATTEMPTS_PROPERTY);
            $fresh->saveQuietly();
            $media->custom_properties = $fresh->custom_properties;
            $media->syncOriginalAttribute('custom_properties');
        }, report: false);
    }

    /**
     * De controle achteraf. Een driver zonder AVIF kan stil het ongewijzigde
     * bestand onder de naam `.avif` opslaan en het als gegenereerd markeren:
     * daarom wordt van elk resultaat de inhoud gesnoven. Is het geen AVIF,
     * dan gaat het bestand weg, staat het formaat weer op niet-gegenereerd
     * en gaat AVIF voor dit proces uit.
     */
    protected function verifyAvif(Media $media, string $name): ?string
    {
        if (! $media->hasGeneratedConversion($name)) {
            return null;
        }

        $disk = Storage::disk($media->conversions_disk ?: $media->disk);
        $path = $media->getPathRelativeToRoot($name);

        if (self::isAvif($this->head($disk, $path))) {
            return null;
        }

        rescue(fn () => $disk->delete($path), report: false);
        $media->markAsConversionNotGenerated($name);

        MediaCapabilities::disableAvif('the ' . MediaCapabilities::imageDriver() . ' driver wrote a file that is not AVIF for media #' . $media->getKey() . ' (' . $name . ')');

        return 'The result is not an AVIF file; AVIF is switched off for this process.';
    }

    /** Of deze eerste bytes van een bestand een AVIF zijn (ISO BMFF met merk `avif` of `avis`). */
    public static function isAvif(string $head): bool
    {
        if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
            return false;
        }

        $size = unpack('N', substr($head, 0, 4))[1] ?? 0;
        $brands = substr($head, 8, max(4, min((int) $size, strlen($head)) - 8));

        return str_contains($brands, 'avif') || str_contains($brands, 'avis');
    }

    /**
     * Ruimt de formaatbestanden van één mediarij op die als "niet meer in
     * gebruik" genoteerd staan (`stale_conversions`: setFocalPoint() noteert
     * de uitsneden van het vorige focuspunt). Pas na de bewaartermijn gaat
     * zo'n bestand weg; staat het formaat intussen weer geregistreerd, dan
     * vervalt de notitie. Geen listing van de schijf: alleen wat genoteerd is.
     */
    protected function cleanUp(Media $media, int $itemId): void
    {
        $days = max(0, (int) config('dashed-files.conversions.cleanup_after_days', 90));
        $disk = Storage::disk($media->conversions_disk ?: $media->disk);

        $stale = (array) $media->getCustomProperty('stale_conversions', []);
        $generated = (array) $media->generated_conversions;
        $before = [$stale, $generated];

        if ($stale === []) {
            return;
        }

        // Wat nu bij het item hoort, plus de miniatuur en `medium`: die gaan nooit weg.
        $item = MediaItem::withoutGlobalScopes()->find($itemId);
        $keep = array_merge(
            array_keys(MediaConversions::libraryDefinitions($item?->conversions, $this->number($item?->getAttribute('focal_x')), $this->number($item?->getAttribute('focal_y')))),
            ['thumb', 'medium', 'responsive'],
        );

        // `medium` is altijd geregistreerd: zijn pad is het sjabloon voor elk ander formaat.
        $template = $media->getPathRelativeToRoot('medium');
        $deleted = 0;

        foreach ($stale as $name => $since) {
            $name = (string) $name;
            $base = str_ends_with($name, ConversionDefinition::AVIF_SUFFIX) ? substr($name, 0, -strlen(ConversionDefinition::AVIF_SUFFIX)) : $name;

            if (in_array($base, $keep, true)) {
                unset($stale[$name]);

                continue;
            }

            if (rescue(fn () => Carbon::parse((string) $since), now(), false)->gt(now()->subDays($days))) {
                continue;
            }

            $extension = $base === $name ? 'webp' : ConversionDefinition::AVIF;
            $path = preg_replace('/-medium\.[a-z0-9]+$/i', '-' . $name . '.' . $extension, $template);

            if (is_string($path) && $path !== $template) {
                $disk->delete($path);
            }

            unset($stale[$name], $generated[$name]);
            $deleted++;
        }

        if ([$stale, $generated] !== $before) {
            $media->setCustomProperty('stale_conversions', $stale);
            $media->generated_conversions = $generated;
            // Stil: dit raakt het bestand van het item niet.
            $media->saveQuietly();
        }

        if ($deleted > 0) {
            app(MediaLibrary::class)->invalidate($itemId, 'conversions-cleaned');
        }
    }

    /**
     * De namen waar deze run bij dit item iets mee moet. Leeg: overslaan.
     *
     * @return array<int, string>
     */
    protected function namesFor(MediaConversionRun $run, object $row): array
    {
        $focalX = $this->number($row->focal_x ?? null);
        $focalY = $this->number($row->focal_y ?? null);

        switch ($run->type) {
            case self::MISSING:
                return $this->missingNames($row, evenIfGivenUp: true);

            case self::FOCAL:
                return $this->missingNames($row, onlyCropping: true, evenIfGivenUp: true);

            case self::FORCE:
                $wanted = $run->names();
                $names = array_keys(MediaConversions::libraryDefinitions($row->conversions, $focalX, $focalY));

                return $wanted === []
                    ? $names
                    : array_values(array_filter($names, fn (string $name) => in_array($name, $wanted, true) || in_array(ConversionDefinition::withoutFocalSuffix($name), $wanted, true)));

            case self::AVIF:
                if (! MediaLibrary::avifEnabled() || ! MediaCapabilities::avif()) {
                    return [];
                }

                $generated = $this->generated($row);
                // Standaard alleen formaten die echt worden uitgeleverd: daar
                // staat een adres van in `conversion_urls`. Met `all` alles.
                $delivered = empty($run->filters['all']) ? array_keys(MediaLibrary::storedUrls($row->conversion_urls ?? null)) : null;
                $names = [];

                foreach (array_keys(MediaConversions::libraryDefinitions($row->conversions, $focalX, $focalY)) as $name) {
                    if (empty($generated[$name]) || ! empty($generated[$name . ConversionDefinition::AVIF_SUFFIX])) {
                        continue;
                    }

                    if ($delivered === null || in_array(ConversionDefinition::withoutFocalSuffix($name), $delivered, true)) {
                        $names[] = $name . ConversionDefinition::AVIF_SUFFIX;
                    }
                }

                return $names;

            case self::CLEANUP:
                return ((array) ($this->properties($row)['stale_conversions'] ?? [])) === [] ? [] : ['*'];
        }

        return [];
    }

    /**
     * De items waar een run over gaat: het huidige bestand van elk item dat
     * een afbeelding is waar de beelddriver formaten van kan maken.
     */
    protected function candidates(?MediaConversionRun $run): \Illuminate\Database\Query\Builder
    {
        $columns = ['items.id', 'items.conversions', 'items.conversion_urls', 'media.id as media_id', 'media.generated_conversions', 'media.custom_properties', 'media.mime_type as media_mime'];

        if (BrowseSchema::hasFocal()) {
            $columns[] = 'items.focal_x';
            $columns[] = 'items.focal_y';
        }

        $ids = $run ? $run->itemIds() : [];

        $query = DB::table('filament_media_library as items')
            ->join('media', fn ($join) => $join
                ->on('media.model_id', '=', 'items.id')
                ->whereIn('media.model_type', [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS])
                ->where('media.collection_name', MediaItem::COLLECTION))
            ->whereIn('media.mime_type', $this->readableMimes())
            ->when($ids !== [], fn ($query) => $query->whereIn('items.id', $ids))
            ->select($columns);

        // Opruimen loopt alleen de rijen af waar iets genoteerd staat, niet
        // de hele bibliotheek. De twee vormen: zoals Laravel de JSON schrijft
        // en zoals MySQL hem teruggeeft.
        if ($run && $run->type === self::CLEANUP) {
            $query->where(fn ($query) => $query
                ->where('media.custom_properties', 'like', '%"stale_conversions":{"%')
                ->orWhere('media.custom_properties', 'like', '%"stale_conversions": {"%'));
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    protected function readableMimes(): array
    {
        return array_values(array_filter(
            ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/heic', 'image/heif', 'image/tiff', 'image/bmp', 'image/x-ms-bmp'],
            fn (string $mime) => MediaConversions::isConvertible($mime),
        ));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{ids: array<int, int>, names: array<int, string>, all?: bool}
     *
     * @throws MediaRejectedException
     */
    protected function cleanFilters(string $type, array $filters): array
    {
        $ids = [];

        foreach ((array) ($filters['ids'] ?? []) as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        $names = [];

        foreach ((array) ($filters['names'] ?? []) as $name) {
            if (is_string($name) && preg_match('/^[a-z0-9][a-z0-9_-]{0,80}$/i', $name)) {
                $names[$name] = $name;
            }
        }

        $ids = array_values($ids);

        if (count($ids) > self::MAX_IDS) {
            // Meer dan er in een run passen. "Wat ontbreekt" kan zonder
            // verlies over alles gaan; elke andere soort zou dan iets anders
            // doen dan gevraagd, dus die weigert.
            if (! in_array($type, self::JOINABLE, true)) {
                throw new MediaRejectedException(__('Een run kan hooguit :max bestanden tegelijk aan. Kies er minder, of start hem zonder selectie.', ['max' => self::MAX_IDS]));
            }

            $ids = [];
        }

        return array_filter(['ids' => $ids, 'names' => array_values($names), 'all' => ! empty($filters['all'])], fn ($value) => $value !== false);
    }

    /**
     * Voegt een aanvraag bij een lopende run. Een item dat de cursor al
     * voorbij is krijgt een tweede doorgang: de run begint na zijn laatste
     * item nog één keer vooraan (wat al gedaan is heeft dan niets meer te doen).
     *
     * @param  array{ids: array<int, int>, names: array<int, string>}  $filters
     */
    protected function join(MediaConversionRun $run, array $filters): void
    {
        DB::transaction(function () use ($run, $filters) {
            $row = MediaConversionRun::query()->whereKey($run->getKey())->lockForUpdate()->first();

            if (! $row) {
                return;
            }

            $current = $row->itemIds();
            $stored = (array) $row->filters;

            if ($current === []) {
                // De lopende run gaat al over alles: alleen wat de cursor voorbij is opnieuw.
                $rewind = $filters['ids'] === [] ? $row->cursor > 0 : min($filters['ids']) <= $row->cursor;
                $ids = [];
            } elseif ($filters['ids'] === []) {
                $rewind = true;
                $ids = [];
            } else {
                $new = array_values(array_diff($filters['ids'], $current));
                $rewind = $new !== [] && min($new) <= $row->cursor;
                $ids = array_merge($current, $new);

                // Past het niet meer in één lijst, dan gaat de run over alles: zo valt er niets stil weg.
                if (count($ids) > self::MAX_IDS) {
                    $ids = [];
                    $rewind = true;
                }
            }

            $stored['ids'] = $ids;
            $stored['rewind'] = $rewind || ! empty($stored['rewind']);

            $row->filters = $stored;
            $row->total = $this->candidates($row)->count();
            $row->save();
        });
    }

    protected function rewind(int|string $id): bool
    {
        return (bool) DB::transaction(function () use ($id) {
            $row = MediaConversionRun::query()->whereKey($id)->lockForUpdate()->first();
            $filters = (array) $row?->filters;

            if (! $row || empty($filters['rewind'])) {
                return false;
            }

            $filters['rewind'] = false;
            $row->filters = $filters;
            $row->cursor = 0;
            $row->save();

            return true;
        });
    }

    /**
     * @param  array{id: int, done: array<int, string>}|null  $partial
     */
    protected function setPartial(int|string $id, ?array $partial): void
    {
        DB::transaction(function () use ($id, $partial) {
            $row = MediaConversionRun::query()->whereKey($id)->lockForUpdate()->first();

            if (! $row) {
                return;
            }

            $filters = (array) $row->filters;

            if ($partial === null && ! isset($filters['partial'])) {
                return;
            }

            if ($partial === null) {
                unset($filters['partial']);
            } else {
                $filters['partial'] = $partial;
            }

            $row->filters = $filters;
            $row->save();
        });
    }

    protected function finish(MediaConversionRun $run): void
    {
        $updated = MediaConversionRun::query()->whereKey($run->getKey())->where('status', MediaConversionRun::RUNNING)->update([
            'status' => MediaConversionRun::DONE,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget(self::HAS_MISSING_KEY);

        if (! $updated) {
            return;
        }

        $run->refresh();

        // Eén verzamelde melding per run, in plaats van een per item.
        if ($run->failed > 0 || $run->skipped > 0) {
            Log::warning(sprintf(
                'Media conversion run #%d (%s) finished: %d of %d items, %d failed, %d skipped. Last problems: %s',
                $run->id,
                $run->type,
                $run->done,
                $run->total,
                $run->failed,
                $run->skipped,
                str_replace("\n", ' | ', (string) $run->last_error),
            ));
        }

        // Na een focuswijziging staat de pagina mogelijk al in de cache met
        // de terugval-uitsnede: nu de echte er zijn, nog één keer legen.
        if ($run->type === self::FOCAL) {
            rescue(fn () => FlushMediaDependentCaches::queueMany($run->itemIds()), report: false);
        }
    }

    /** Of de keten van deze run stilligt: geen hartslag sinds zoveel seconden. */
    protected function chainIsStale(MediaConversionRun $run, int $seconds): bool
    {
        $last = $run->heartbeat_at ?? $run->updated_at ?? $run->created_at;

        return $last === null || $last->lt(now()->subSeconds($seconds));
    }

    /**
     * @return array<string, mixed>
     */
    protected function generated(object $row): array
    {
        $generated = json_decode($row->generated_conversions ?: '[]', true);

        return is_array($generated) ? $generated : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function properties(object $row): array
    {
        $properties = $row->custom_properties ?? null;
        $properties = is_string($properties) ? json_decode($properties, true) : $properties;

        return is_array($properties) ? $properties : [];
    }

    protected function head(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path): string
    {
        try {
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                return '';
            }

            $head = (string) fread($stream, 64);
            fclose($stream);

            return $head;
        } catch (Throwable) {
            return '';
        }
    }

    protected function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function now(): float
    {
        return self::$clock ? (float) (self::$clock)() : microtime(true);
    }

    /** Het slot rond het genereren van formaten van één mediarij; ook het losse genereren op het leespad neemt het. */
    public static function mediaLockKey(int|string $mediaId): string
    {
        return 'media-conversions-writing-' . $mediaId;
    }

    private static function requestLockKey(int $itemId): string
    {
        return 'media-conversions-requested-' . $itemId;
    }

    private static function requestAttemptsKey(int $itemId): string
    {
        return 'media-conversions-request-attempts-' . $itemId;
    }
}
