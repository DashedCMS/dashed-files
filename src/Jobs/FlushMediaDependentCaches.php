<?php

namespace Dashed\DashedFiles\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedFiles\Classes\Queue\WorkBudget;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Illuminate\Database\Eloquent\Relations\Relation;
use Dashed\DashedFiles\Classes\Usage\MediaReferenceWriter;
use Dashed\DashedFiles\Classes\Usage\MediaReferenceExtractor;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * Na het vervangen of weggooien van een bestand: alles legen wat het oude
 * beeld nog vasthoudt. Uniek en een minuut vertraagd, zodat tien bewerkingen
 * achter elkaar één ronde geven. De taak draagt daarom zelf geen id's: die
 * staan in de cache en de taak pakt op wat er op dat moment ligt.
 *
 * Voor een vervangen bestand werkt de taak ook het adres bij dat rijke tekst
 * bij een ingesloten afbeelding bewaart, in elk model dat de gebruiksindex
 * kent. Zonder model-events: een product hoort zijn feed niet opnieuw te
 * bouwen omdat een afbeelding een nieuw adres kreeg.
 *
 * Dat bijwerken gaat per item binnen een werkbudget: wat niet meer past
 * gaat terug in de lijst en komt in de volgende ronde. Bijwerken is
 * herhaalbaar (een adres dat al klopt wordt niet opnieuw geschreven), dus
 * een item dat halverwege terug moest kan veilig opnieuw.
 */
class FlushMediaDependentCaches implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const PENDING_KEY = 'media-dependent-caches-pending';

    public const WINDOW_SECONDS = 60;

    // Daarboven alleen nog de flush per site; modellen aanraken per item loont dan niet meer.
    public const MAX_PENDING = 2000;

    public int $tries = 1;

    public int $timeout = WorkBudget::MAX_JOB_TIMEOUT;

    public int $uniqueFor = 600;

    public function __construct()
    {
        if (filled($queue = config('dashed-files.ingest.queue'))) {
            $this->onQueue((string) $queue);
        }
    }

    /**
     * @param  bool  $rewriteSources  het bestand is vervangen: ook het opgeslagen adres in rijke tekst bijwerken
     */
    public static function queueFor(int $itemId, bool $rewriteSources = false): void
    {
        self::queueMany([$itemId], $rewriteSources);
    }

    /**
     * Eén ronde voor een hele bulkactie. Een lege lijst zet alleen de flush per site klaar.
     *
     * @param  array<int, int>  $itemIds
     */
    public static function queueMany(array $itemIds, bool $rewriteSources = false): void
    {
        rescue(function () use ($itemIds, $rewriteSources) {
            if ($itemIds !== []) {
                self::locked(function () use ($itemIds, $rewriteSources) {
                    $pending = (array) Cache::get(self::PENDING_KEY, []);

                    foreach ($itemIds as $itemId) {
                        if (count($pending) >= self::MAX_PENDING && ! isset($pending[$itemId])) {
                            break;
                        }

                        $pending[$itemId] = $rewriteSources || ! empty($pending[$itemId]);
                    }

                    Cache::put(self::PENDING_KEY, $pending, now()->addDay());
                });
            }

            self::dispatch()->delay(now()->addSeconds(self::WINDOW_SECONDS));
        });
    }

    // Toevoegen en ophalen onder hetzelfde slot: anders valt een id dat net
    // wordt bijgeschreven terwijl de taak de lijst leegt tussen wal en schip.
    private static function locked(callable $callback): mixed
    {
        $lock = Cache::lock(self::PENDING_KEY . '-lock', 10);

        try {
            rescue(fn () => $lock->block(5), report: false);

            return $callback();
        } finally {
            rescue(fn () => $lock->release(), report: false);
        }
    }

    public function handle(): void
    {
        $pending = (array) self::locked(fn () => Cache::pull(self::PENDING_KEY, []));
        $ids = array_map('intval', array_keys($pending));
        $replaced = array_map('intval', array_keys(array_filter($pending)));

        $leftover = [];

        if ($replaced !== [] && BrowseSchema::hasUsages()) {
            $budget = WorkBudget::start();

            foreach ($replaced as $index => $itemId) {
                // Minstens één item per ronde.
                if ($index > 0 && $budget->spent()) {
                    $leftover = array_slice($replaced, $index);

                    break;
                }

                $finished = rescue(fn () => $this->rewriteSources([$itemId], fn () => $budget->spent()), true);

                if ($finished === false) {
                    $leftover = array_slice($replaced, $index);

                    break;
                }
            }
        }

        // Wat niet meer paste: terug in de lijst, voor de volgende ronde. Die
        // ronde raakt ook de modellen aan; deze slaat ze over.
        if ($leftover !== []) {
            self::queueMany($leftover, true);
            $ids = array_values(array_diff($ids, $leftover));
        }

        // Per site en nooit alles: flushAll() gooit ook de cache van sites weg die er niets mee te maken hebben.
        $invalidator = 'Dashed\\DashedCore\\Classes\\Caching\\CacheInvalidator';

        if (class_exists($invalidator)) {
            foreach (Sites::getSites() as $site) {
                $siteId = (string) ($site['id'] ?? '');

                if ($siteId === '') {
                    continue;
                }

                rescue(fn () => $invalidator::flushSite($siteId));
                rescue(fn () => $invalidator::flushFrontendShared($siteId));
            }
        }

        if ($ids !== [] && BrowseSchema::hasUsages()) {
            $this->touchUsingModels($ids);
        }
    }

    /**
     * Rijke tekst bewaart bij een ingesloten afbeelding ook het adres. Na een
     * vervanging wijst dat naar het oude bestand, dat na de bewaartermijn
     * verdwijnt. Hier krijgt elke zekere verwijzing het nieuwe adres; een
     * gegokte verwijzing blijft staan.
     *
     * @param  array<int, int>  $itemIds
     * @param  (callable(): bool)|null  $stop  het budget is op: stoppen na het lopende model
     * @return bool of elk model aan de beurt is geweest
     */
    private function rewriteSources(array $itemIds, ?callable $stop = null): bool
    {
        $urls = [];

        foreach (MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->withTrashed()->with('media')->whereKey($itemIds)->get() as $item) {
            // Een privé-item heeft geen adres voor de site: er valt niets in rijke tekst te schrijven.
            $url = \Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($item) ? null : rescue(fn () => $item->file()?->getUrl(), null, false);

            if (is_string($url) && $url !== '') {
                $urls[(int) $item->getKey()] = $url;
            }
        }

        if ($urls === []) {
            return true;
        }

        $wrote = false;
        $extractor = app(MediaReferenceExtractor::class);
        $writer = app(MediaReferenceWriter::class);

        $rows = DB::table(BrowseSchema::USAGES)
            ->whereIn('media_item_id', array_keys($urls))
            ->when(BrowseSchema::hasGuesses(), fn ($query) => $query->where('is_guess', false))
            ->distinct()
            ->get(['model_type', 'model_id']);

        foreach ($rows->groupBy('model_type') as $class => $group) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            foreach ($group->pluck('model_id')->chunk(100) as $chunk) {
                foreach ($class::query()->withoutGlobalScopes()->whereKey($chunk->all())->get() as $model) {
                    // Pas na een geschreven model: een herhaalde ronde komt zo
                    // altijd verder dan de vorige, ook bij een item op heel veel plekken.
                    if ($wrote && $stop !== null && $stop()) {
                        return false;
                    }

                    try {
                        if ($extractor->rewriteSources($model, $urls) > 0) {
                            $writer->persist($model);
                            $wrote = true;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Zet updated_at van elk model dat een van deze items gebruikt op nu,
     * zodat blokcaches met updated_at in de sleutel verversen. Rechtstreeks
     * in de tabel: zonder model-events, dus zonder herindexering, logboek of
     * andere haken.
     *
     * @param  array<int, int>  $itemIds
     */
    private function touchUsingModels(array $itemIds): void
    {
        $perType = [];

        foreach (array_chunk($itemIds, 500) as $chunk) {
            foreach (DB::table(BrowseSchema::USAGES)->whereIn('media_item_id', $chunk)->distinct()->get(['model_type', 'model_id']) as $row) {
                $perType[$row->model_type][(int) $row->model_id] = true;
            }
        }

        foreach ($perType as $class => $ids) {
            $this->touch($class, array_keys($ids), 0);
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function touch(string $class, array $ids, int $depth): void
    {
        $class = Relation::getMorphedModel($class) ?? $class;

        if (! class_exists($class) || $ids === [] || $depth > 1) {
            return;
        }

        try {
            $model = new $class();

            if (! $model->usesTimestamps()) {
                return;
            }

            $table = $model->getTable();
            $parents = [];

            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table($table)->whereIn($model->getKeyName(), $chunk)->update([$model->getUpdatedAtColumn() => now()]);

                // Custom-blokken en metadata hangen aan een ouder, en het is
                // de updated_at van die ouder die in de cachesleutel zit.
                foreach (['blockable', 'metadatable', 'model'] as $morph) {
                    if (! $this->hasMorph($table, $morph)) {
                        continue;
                    }

                    foreach (DB::table($table)->whereIn($model->getKeyName(), $chunk)->get([$morph . '_type as type', $morph . '_id as id']) as $row) {
                        if (is_string($row->type) && $row->id !== null) {
                            $parents[$row->type][(int) $row->id] = true;
                        }
                    }
                }
            }

            foreach ($parents as $parentClass => $parentIds) {
                $this->touch($parentClass, array_keys($parentIds), $depth + 1);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function hasMorph(string $table, string $morph): bool
    {
        static $known = [];

        return $known[$table . ':' . $morph] ??= rescue(
            fn () => DB::getSchemaBuilder()->hasColumn($table, $morph . '_type') && DB::getSchemaBuilder()->hasColumn($table, $morph . '_id'),
            false,
            false,
        );
    }
}
