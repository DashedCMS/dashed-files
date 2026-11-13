<?php

namespace Dashed\DashedFiles\Classes\Usage;

use Throwable;
use Illuminate\Support\Facades\DB;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Dashed\DashedCore\Models\Customsetting;
use Illuminate\Database\Eloquent\SoftDeletes;
use Dashed\DashedFiles\Jobs\BackfillMediaUsages;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;

/**
 * Houdt `dashed__media_usages` bij: per model de media-items waar het naar
 * verwijst. Bijwerken gebeurt op `saved` en `deleted` van een aangemeld
 * model, met een nulmeting en een nachtelijke herberekening als vangnet
 * voor wat zonder model-events is geschreven.
 */
class MediaUsageIndexer
{
    public const READY_SETTING = 'media_usages_ready_at';

    public const PENDING_SETTING = 'media_usages_baseline_pending';

    public const UNBUILDABLE_SETTING = 'media_usages_unbuildable_blocks';

    // De nachtronde kijkt alleen naar wat kort geleden is gewijzigd; één keer
    // per week loopt alles.
    public const RECENT_DAYS = 2;

    // Voor een model zonder updated_at: de laatste zoveel rijen.
    public const RECENT_ROWS = 5000;

    private const READY_MEMO = 'dashed-files.media-usages-ready';

    public function __construct(private readonly MediaReferenceExtractor $extractor)
    {
    }

    /**
     * Binnen een transactie van de aanroeper pas na de commit: een deadlock
     * of een fout in de index mag die transactie nooit terugrollen, en na een
     * rollback valt er niets te indexeren. Zonder open transactie meteen.
     */
    private function afterCommit(callable $callback): void
    {
        DB::afterCommit(fn () => rescue($callback));
    }

    /**
     * De luisteraar op `eloquent.saved: *`. Vuurt voor elk model in het CMS,
     * dus eerst de goedkoopste toetsen en pas daarna werk.
     */
    public function handleSaved(Model $model): void
    {
        $class = MediaUsageRegistry::resolve($model::class);

        if ($class === null) {
            return;
        }

        $attributes = $this->extractor->attributesOf($model);

        if ($attributes === []) {
            return;
        }

        $changes = $model->getChanges();

        if ($changes === []) {
            // Na een insert is getChanges() leeg; wasRecentlyCreated blijft op
            // dezelfde instantie ook na een latere save() waar, dus alleen
            // samen zeggen ze "nieuw". Een bestaand model zonder wijziging
            // heeft niets te herindexeren.
            if (! $model->wasRecentlyCreated) {
                return;
            }

            // Een nieuw model zonder iets in zijn mediavelden: geen query.
            $raw = $model->getAttributes();

            foreach (array_keys($attributes) as $attribute) {
                if (! in_array($raw[$attribute] ?? null, [null, '', '[]', '{}', 'null'], true)) {
                    $this->afterCommit(fn () => $this->reindex($model));

                    return;
                }
            }

            return;
        }

        if (array_intersect_key($changes, $attributes) !== []) {
            $this->afterCommit(fn () => $this->reindex($model));
        }
    }

    public function handleDeleted(Model $model): void
    {
        if (MediaUsageRegistry::resolve($model::class) === null) {
            return;
        }

        // In de prullenbak verwijst het model nog steeds: terugzetten moet
        // niets hoeven herstellen. Alleen een echte verwijdering ruimt op.
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && ! $model->isForceDeleting()) {
            return;
        }

        $this->afterCommit(fn () => $this->forget($model));
    }

    public function reindex(Model $model): void
    {
        $this->reindexMany([$model]);
    }

    /**
     * Zet de rijen van deze modellen (allemaal van dezelfde klasse) gelijk
     * aan wat er nu in staat: één query om te lezen wat er is, en alleen een
     * transactie als er iets af of bij moet.
     *
     * @param  iterable<int, Model>  $models
     */
    public function reindexMany(iterable $models): void
    {
        if (! BrowseSchema::hasUsages()) {
            return;
        }

        $wanted = [];
        $type = null;
        $guesses = BrowseSchema::hasGuesses();

        foreach ($models as $model) {
            $class = MediaUsageRegistry::resolve($model::class);

            if ($class === null || ! $model->exists || ! is_numeric($model->getKey())) {
                continue;
            }

            $type ??= $class;

            if ($type !== $class) {
                continue;
            }

            $modelId = (int) $model->getKey();
            $siteId = $this->siteIdOf($model);
            $wanted[$modelId] = [];

            foreach ($this->extractor->extract($model) as $reference) {
                $field = $this->extractor->fieldName($model, $reference['field']);
                $guess = $guesses && $reference['guess'];
                $row = [
                    'media_item_id' => $reference['media_item_id'],
                    'model_type' => $class,
                    'model_id' => $modelId,
                    'field' => $field,
                    'locale' => $reference['locale'],
                    'site_id' => $siteId,
                ];

                // De vlag hoort bij de sleutel: een gok die zeker wordt (of andersom) is een andere rij.
                $wanted[$modelId][$reference['media_item_id'] . '|' . $field . '|' . $reference['locale'] . '|' . (int) $guess] = $guesses ? $row + ['is_guess' => $guess] : $row;
            }
        }

        if ($wanted === []) {
            return;
        }

        $existing = [];

        foreach (DB::table(BrowseSchema::USAGES)->where('model_type', $type)->whereIn('model_id', array_keys($wanted))->get($guesses ? ['id', 'media_item_id', 'model_id', 'field', 'locale', 'is_guess'] : ['id', 'media_item_id', 'model_id', 'field', 'locale']) as $row) {
            $existing[(int) $row->model_id][$row->media_item_id . '|' . $row->field . '|' . $row->locale . '|' . (int) ($row->is_guess ?? 0)] = (int) $row->id;
        }

        $delete = [];
        $insert = [];

        foreach ($wanted as $modelId => $references) {
            $have = $existing[$modelId] ?? [];

            foreach (array_diff_key($have, $references) as $id) {
                $delete[] = $id;
            }

            foreach (array_diff_key($references, $have) as $row) {
                $insert[] = $row;
            }
        }

        if ($delete === [] && $insert === []) {
            return;
        }

        $now = now();
        $insert = array_map(fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now], $insert);

        // Drie pogingen: Laravel herhaalt de transactie bij een deadlock.
        DB::transaction(function () use ($delete, $insert) {
            foreach (array_chunk($delete, 500) as $chunk) {
                DB::table(BrowseSchema::USAGES)->whereIn('id', $chunk)->delete();
            }

            foreach (array_chunk($insert, 200) as $chunk) {
                // OrIgnore: een tweede proces kan dezelfde rij net hebben geschreven.
                DB::table(BrowseSchema::USAGES)->insertOrIgnore($chunk);
            }
        }, 3);
    }

    public function forget(Model $model): void
    {
        $class = MediaUsageRegistry::resolve($model::class);

        if ($class === null || ! BrowseSchema::hasUsages()) {
            return;
        }

        DB::table(BrowseSchema::USAGES)->where('model_type', $class)->where('model_id', $model->getKey())->delete();
    }

    /** Elke verwijzing NAAR dit item: het item zelf is definitief weg. */
    public function forgetTarget(int $itemId): void
    {
        if (BrowseSchema::hasUsages()) {
            DB::table(BrowseSchema::USAGES)->where('media_item_id', $itemId)->delete();
        }
    }

    /**
     * Elke klasse die de nulmeting afloopt, het instellingenmodel inbegrepen.
     *
     * @return array<int, class-string>
     */
    public function indexableModels(): array
    {
        $models = array_values(array_filter(MediaUsageRegistry::models(), fn (string $class) => class_exists($class)));

        if (MediaUsageRegistry::settings() !== [] && class_exists(MediaUsageRegistry::SETTING_MODEL)) {
            $models[] = MediaUsageRegistry::SETTING_MODEL;
        }

        return $models;
    }

    /**
     * Herindexeert één portie van een klasse, op id. Alleen rijen waar in
     * een aangemelde kolom iets staat: `OrderLog` en `ChatMessage` hebben op
     * een webshop miljoenen rijen, bijna allemaal zonder bijlage.
     *
     * @param  string|null  $since  alleen rijen die sinds dit moment zijn gewijzigd (de nachtronde)
     * @return int het hoogste id in de portie, of 0 als de klasse klaar is
     */
    public function reindexChunk(string $class, int $afterId, int $chunk, ?string $since = null): int
    {
        $model = new $class();
        $key = $model->getKeyName();
        $query = $this->rowsQuery($class);

        if ($since !== null) {
            if ($model->usesTimestamps() && $model->getUpdatedAtColumn()) {
                $query->where($model->getUpdatedAtColumn(), '>=', $since);
            } elseif ($afterId === 0) {
                // Zonder updated_at: de laatste rijen.
                $afterId = max(0, (int) $class::query()->withoutGlobalScopes()->toBase()->max($key) - self::RECENT_ROWS);
            }
        }

        $rows = $query->where($key, '>', $afterId)->orderBy($key)->limit(max(1, $chunk))->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $this->reindexMany($rows);

        return $rows->count() < $chunk ? 0 : (int) $rows->last()->getKey();
    }

    /**
     * De rijen van een klasse die een verwijzing kunnen dragen.
     */
    private function rowsQuery(string $class, bool $empty = false): \Illuminate\Database\Eloquent\Builder
    {
        // Zonder de scopes van het model: een pagina van een andere site, een
        // niet-gepubliceerd product of iets in de prullenbak verwijst net zo goed.
        $query = $class::query()->withoutGlobalScopes();

        if ($class === MediaUsageRegistry::SETTING_MODEL) {
            return $query->whereIn('name', MediaUsageRegistry::settings());
        }

        // Als tekst vergeleken: een JSON-kolom op MySQL is anders nooit gelijk aan '[]'.
        $grammar = $query->getQuery()->getGrammar();
        $columns = array_map(
            fn (string $column) => 'CAST(' . $grammar->wrap($query->getModel()->qualifyColumn($column)) . ' AS CHAR)',
            array_keys(MediaUsageRegistry::for($class)),
        );
        $blank = "('', '[]', '{}', 'null', '\"\"')";

        if ($empty) {
            foreach ($columns as $column) {
                $query->whereRaw("({$column} IS NULL OR {$column} IN {$blank})");
            }

            return $query;
        }

        return $query->where(function ($any) use ($columns, $blank) {
            foreach ($columns as $column) {
                $any->orWhereRaw("({$column} IS NOT NULL AND {$column} NOT IN {$blank})");
            }
        });
    }

    /**
     * Rijen van modellen die er niet meer zijn (verwijderd zonder events), en
     * van modellen waarvan elke aangemelde kolom intussen leeg is: die slaat
     * de ronde hierboven over, dus hun oude rijen moeten hier weg.
     */
    public function pruneOrphans(string $class): int
    {
        if (! BrowseSchema::hasUsages() || ! class_exists($class)) {
            return 0;
        }

        $model = new $class();

        $removed = DB::table(BrowseSchema::USAGES)
            ->where('model_type', $class)
            ->whereNotIn('model_id', fn ($query) => $query->select($model->getKeyName())->from($model->getTable()))
            ->delete();

        if ($class !== MediaUsageRegistry::SETTING_MODEL && MediaUsageRegistry::for($class) !== []) {
            $emptied = $this->rowsQuery($class, empty: true)->toBase()->select($model->getKeyName());

            $removed += DB::table(BrowseSchema::USAGES)->where('model_type', $class)->whereIn('model_id', $emptied)->delete();
        }

        return $removed;
    }

    /**
     * Zet per klasse een taak in de wachtrij. De index geldt pas als gevuld
     * zodra de laatste klasse klaar is.
     *
     * @param  bool  $markReady  of de laatste taak de vlag "gevuld" zet
     */
    public function queueBaseline(?array $classes = null, bool $markReady = true): int
    {
        $classes ??= $this->indexableModels();

        if ($classes === []) {
            if ($markReady) {
                $this->markReady();
            }

            return 0;
        }

        $this->setting(self::PENDING_SETTING, json_encode(['classes' => array_values($classes), 'ready' => $markReady]));

        foreach ($classes as $class) {
            BackfillMediaUsages::dispatch($class);
        }

        return count($classes);
    }

    /**
     * De nachtronde: alleen wat kort geleden is gewijzigd. Raakt de vlag niet.
     */
    public function queueRecent(?array $classes = null): int
    {
        $classes ??= $this->indexableModels();
        $since = now()->subDays(self::RECENT_DAYS)->toDateTimeString();

        foreach ($classes as $class) {
            BackfillMediaUsages::dispatch($class, 0, 200, $since);
        }

        return count($classes);
    }

    /** Een klasse van de nulmeting is klaar; de laatste zet de vlag. */
    public function classFinished(string $class): void
    {
        $lock = Cache::lock('media-usages-baseline', 30);

        try {
            $lock->block(10);
        } catch (Throwable) {
            // Zonder slot toch doorgaan: liever een dubbele schrijf dan een nulmeting die nooit klaar meldt.
        }

        try {
            $pending = $this->jsonSetting(self::PENDING_SETTING);

            if (! is_array($pending) || ! is_array($pending['classes'] ?? null)) {
                return;
            }

            $pending['classes'] = array_values(array_diff($pending['classes'], [$class]));

            if ($pending['classes'] !== []) {
                $this->setting(self::PENDING_SETTING, json_encode($pending));

                return;
            }

            $this->setting(self::PENDING_SETTING, '');

            if ($pending['ready'] ?? true) {
                $this->markReady();
            } else {
                $this->recordUnbuildable();
            }
        } finally {
            rescue(fn () => $lock->release(), report: false);
        }
    }

    public function markReady(): void
    {
        $this->setting(self::READY_SETTING, now()->toIso8601String());
        $this->recordUnbuildable();
        $this->forgetReadyMemo();
    }

    /**
     * Tot de volgende volledige ronde klaar is zegt de index niets: geen
     * "ongebruikt", geen samenvoegen.
     */
    public function clearReady(): void
    {
        $this->setting(self::READY_SETTING, '');
        $this->forgetReadyMemo();
    }

    private function forgetReadyMemo(): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove(self::READY_MEMO);
        }
    }

    /**
     * Bewaart welke bloktypen bij de laatste ronde geen schema gaven, voor
     * de doctor en de audit.
     */
    public function recordUnbuildable(): void
    {
        rescue(fn () => $this->setting(self::UNBUILDABLE_SETTING, json_encode(app(MediaFieldMap::class)->unbuildable())));
    }

    /**
     * @return array<string, string> bloktype => reden, van de laatste ronde
     */
    public function unbuildable(): array
    {
        $stored = $this->jsonSetting(self::UNBUILDABLE_SETTING);

        return is_array($stored) ? array_filter($stored, 'is_string') : [];
    }

    /**
     * Of de nulmeting ooit is afgerond. Tot dan zegt een lege index niets en
     * toont de beheerpagina geen "ongebruikt".
     */
    public function isReady(): bool
    {
        $request = app()->bound('request') ? request() : null;

        if ($request && is_bool($memo = $request->attributes->get(self::READY_MEMO))) {
            return $memo;
        }

        $ready = BrowseSchema::hasUsages() && filled(rescue(fn () => $this->setting(self::READY_SETTING), null, false));

        $request?->attributes->set(self::READY_MEMO, $ready);

        return $ready;
    }

    private function siteIdOf(Model $model): ?string
    {
        $siteId = $model->getAttributes()['site_id'] ?? null;

        return is_scalar($siteId) && (string) $siteId !== '' ? mb_substr((string) $siteId, 0, 64) : null;
    }

    /**
     * Een instelling die JSON draagt. Customsetting geeft zo'n waarde soms al
     * als array terug en soms als tekst; beide zijn goed.
     */
    private function jsonSetting(string $name): mixed
    {
        $value = rescue(fn () => $this->setting($name), null, false);

        return is_string($value) ? json_decode($value, true) : $value;
    }

    // Installatiebreed, dus op de eerste site.
    private function setting(string $name, ?string $value = null): mixed
    {
        $siteId = (string) (Sites::getFirstSite()['id'] ?? '');

        if (func_num_args() === 1) {
            return Customsetting::get($name, $siteId);
        }

        Customsetting::set($name, $value, $siteId);

        return $value;
    }
}
