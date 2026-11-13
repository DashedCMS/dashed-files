<?php

namespace Dashed\DashedFiles\Classes\Usage;

use Throwable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Illuminate\Validation\ValidationException;
use Dashed\DashedFiles\Classes\Browse\MediaBulk;
use Dashed\DashedFiles\Jobs\MergeMediaDuplicates;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Browse\MediaTagging;
use Dashed\DashedFiles\Classes\Browse\MediaFolderTree;
use Dashed\DashedFiles\Jobs\FlushMediaDependentCaches;

/**
 * Samenvoegen van dubbele bestanden. Elke ZEKERE verwijzing naar een
 * duplicaat gaat naar het item dat blijft; daarna gaat het duplicaat naar
 * de prullenbak. Bij twijfel gebeurt er niets: een duplicaat waar nog een
 * gegokte, verouderde of niet om te zetten verwijzing naar wijst blijft
 * staan. Elke omgezette verwijzing staat in `dashed__media_merges`, zodat
 * undo() precies die plekken kan terugzetten.
 *
 * De modellen worden bewaard zonder model-events (MediaReferenceWriter):
 * de index en de caches van dat model lopen hier expliciet mee, maar de
 * eigen nabewerking van een model (logboek, zoekindex, webhooks) niet.
 */
class MediaDuplicates
{
    // Tot zoveel te wijzigen modellen in het verzoek zelf, daarboven in de wachtrij.
    public const SYNC_LIMIT = 50;

    public function __construct(
        private readonly MediaReferenceExtractor $extractor,
        private readonly MediaUsageIndexer $indexer,
        private readonly MediaReferenceWriter $writer,
    ) {
    }

    /**
     * @param  array<int, int>  $otherIds
     * @return array{batch: string, queued: bool, converted: int, failed: int, trashed: array<int, int>, kept_out: array<int, int>}
     */
    public function merge(MediaItem $keep, array $otherIds, ?int $userId = null): array
    {
        $otherIds = array_values(array_diff(array_unique(array_map('intval', $otherIds)), [(int) $keep->getKey()]));

        // Zonder gevulde index is niet te weten waar de duplicaten gebruikt
        // worden, en zou samenvoegen bestanden weggooien die nog in gebruik zijn.
        if (! BrowseSchema::hasUsages() || ! BrowseSchema::hasMerges() || ! $this->indexer->isReady()) {
            throw ValidationException::withMessages(['ids' => __('Samenvoegen kan pas als de lijst "waar gebruikt" is opgebouwd. Draai eerst dashed:media-usages.')]);
        }

        if ($otherIds === []) {
            throw ValidationException::withMessages(['ids' => __('Kies minstens twee bestanden om samen te voegen.')]);
        }

        if ($keep->trashed()) {
            throw ValidationException::withMessages(['ids' => __('Het bestand dat blijft ligt in de prullenbak. Zet het eerst terug.')]);
        }

        $others = MediaItem::query()->whereKey($otherIds)->get();
        $checksum = (string) $keep->getAttribute('checksum');

        if ($checksum === '' || $others->count() !== count($otherIds) || $others->contains(fn (MediaItem $item) => (string) $item->getAttribute('checksum') !== $checksum)) {
            throw ValidationException::withMessages(['ids' => __('Alleen bestanden met precies dezelfde inhoud kunnen worden samengevoegd.')]);
        }

        // Een chatbijlage is van een bezoeker en verdwijnt met zijn gesprek.
        if ($this->sourceOf($keep) === 'chat' || $others->contains(fn (MediaItem $item) => $this->sourceOf($item) === 'chat')) {
            throw ValidationException::withMessages(['ids' => __('Een bijlage uit een chatgesprek kan niet worden samengevoegd.')]);
        }

        // Over de grens tussen privé en publiek heen samenvoegen zou een
        // plek op de site naar een bestand laten wijzen dat de site niet kan
        // tonen, of een privé-bestand vervangen door een publiek.
        $private = \Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($keep);

        if ($others->contains(fn (MediaItem $item) => \Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($item) !== $private)) {
            throw ValidationException::withMessages(['ids' => __('Een privé-bestand en een publiek bestand kunnen niet worden samengevoegd.')]);
        }

        $batch = (string) Str::uuid();

        if ($this->referencingModels($otherIds)->count() > self::SYNC_LIMIT) {
            MergeMediaDuplicates::dispatch((int) $keep->getKey(), $otherIds, $batch, $userId);

            return ['batch' => $batch, 'queued' => true, 'converted' => 0, 'failed' => 0, 'trashed' => [], 'kept_out' => []];
        }

        return $this->run($keep, $otherIds, $batch, $userId);
    }

    /**
     * Het eigenlijke werk, ook vanuit de wachtrijtaak.
     *
     * @param  array<int, int>  $otherIds
     * @return array{batch: string, queued: bool, converted: int, failed: int, trashed: array<int, int>, kept_out: array<int, int>}
     */
    public function run(MediaItem $keep, array $otherIds, string $batch, ?int $userId = null): array
    {
        $step = $this->rewriteStep($keep, $otherIds, $batch, $userId);

        return $this->finish($keep, $otherIds, $batch, $userId, $step['converted']);
    }

    /**
     * Het omzetten in porties, voor de wachtrijtaak: de modellen op volgorde
     * van klasse en id, vanaf `$after`, tot `$stop` zegt dat het budget op is
     * (minstens één model per slag). De volgorde en het beginpunt maken het
     * hervatbaar: een model dat niet om te zetten was blijft in de index
     * staan, maar komt in een volgende slag niet opnieuw aan de beurt.
     *
     * @param  array<int, int>  $otherIds
     * @param  array{0: string, 1: int}|null  $after  het laatst behandelde model
     * @param  (callable(): bool)|null  $stop
     * @return array{done: bool, after: array{0: string, 1: int}|null, converted: int}
     */
    public function rewriteStep(MediaItem $keep, array $otherIds, string $batch, ?int $userId = null, ?array $after = null, ?callable $stop = null): array
    {
        $keepId = (int) $keep->getKey();
        $otherIds = MediaItem::query()->whereKey($otherIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $replace = array_fill_keys($otherIds, $keepId);
        $urls = array_filter([$keepId => (\Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($keep) ? '' : (string) rescue(fn () => $keep->file()?->getUrl(), '', false))]);
        $converted = 0;
        $groups = [];
        $handled = 0;

        foreach ($this->referencingModels($otherIds, $after) as $row) {
            if ($handled > 0 && $stop !== null && $stop()) {
                $this->afterRewriting([], $groups);

                return ['done' => false, 'after' => $after, 'converted' => $converted];
            }

            try {
                $converted += $this->rewriteModel((string) $row->model_type, (int) $row->model_id, $replace, $urls, $batch, $userId, $groups);
            } catch (Throwable $exception) {
                report($exception);
            }

            $handled++;
            $after = [(string) $row->model_type, (int) $row->model_id];
        }

        $this->afterRewriting([], $groups);

        return ['done' => true, 'after' => $after, 'converted' => $converted];
    }

    /**
     * Na het omzetten: wat nog naar een duplicaat wijst houdt het buiten de
     * prullenbak, de rest gaat erin, en het blijvende item neemt teksten en
     * tags over.
     *
     * @param  array<int, int>  $otherIds
     * @return array{batch: string, queued: bool, converted: int, failed: int, trashed: array<int, int>, kept_out: array<int, int>}
     */
    public function finish(MediaItem $keep, array $otherIds, string $batch, ?int $userId, int $converted): array
    {
        $keepId = (int) $keep->getKey();
        $others = MediaItem::query()->whereKey($otherIds)->get();
        $otherIds = $others->modelKeys();

        // Elk omgezet model is hierboven opnieuw geïndexeerd. Wat de index nu
        // nog naar een duplicaat laat wijzen (een gok, een verouderde rij,
        // een model dat niet te laden was) is twijfel, en bij twijfel blijft
        // het duplicaat buiten de prullenbak.
        $failed = $otherIds === [] ? [] : DB::table(BrowseSchema::USAGES)
            ->whereIn('media_item_id', $otherIds)
            ->groupBy('media_item_id')
            ->selectRaw('media_item_id, count(*) as aggregate')
            ->pluck('aggregate', 'media_item_id')
            ->map(fn ($count) => (int) $count)
            ->all();

        $this->inherit($keep, $others);

        $keptOut = array_map('intval', array_keys($failed));
        $trash = array_values(array_diff($otherIds, $keptOut));

        if ($trash !== []) {
            // Eén regel per samengevoegd item, ook zonder omgezette verwijzing:
            // daaraan herkent de prullenbak "dit is samengevoegd" voor ongedaan maken.
            DB::table(BrowseSchema::MERGES)->insert(array_map(fn (int $id) => [
                'batch' => $batch, 'kept_item_id' => $keepId, 'merged_item_id' => $id, 'model_type' => null, 'model_id' => null,
                'field' => null, 'locale' => '', 'user_id' => $userId, 'created_at' => now(),
            ], $trash));

            app(MediaBulk::class)->deleteItems($trash, $userId);
        }

        $this->afterRewriting([$keepId], []);

        return [
            'batch' => $batch,
            'queued' => false,
            'converted' => $converted,
            'failed' => array_sum($failed),
            'trashed' => $trash,
            'kept_out' => array_values($keptOut),
        ];
    }

    /**
     * Zet de verwijzingen van een samenvoeging terug naar het samengevoegde
     * item. De overgenomen alt-tekst, het bijschrift en de tags blijven op
     * het item dat bleef staan.
     *
     * @return array{restored: int, failed: int, items: array<int, int>, missing: array<int, int>}
     */
    public function undo(string $batch): array
    {
        $rows = BrowseSchema::hasMerges() && preg_match('/^[0-9a-f-]{36}$/i', $batch)
            ? DB::table(BrowseSchema::MERGES)->where('batch', $batch)->orderBy('id')->get()
            : collect();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['batch' => __('Deze samenvoeging is niet (meer) terug te draaien.')]);
        }

        $mergedIds = $rows->pluck('merged_item_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $existing = MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->withTrashed()->whereKey($mergedIds)->get()->keyBy(fn (MediaItem $item) => (int) $item->getKey());
        $missing = array_values(array_diff($mergedIds, $existing->keys()->all()));

        // Eerst terug uit de prullenbak: een verwijzing hoort niet naar iets te wijzen dat weg is.
        $trashed = $existing->filter(fn (MediaItem $item) => $item->trashed())->keys()->all();

        if ($trashed !== []) {
            app(MediaBulk::class)->restoreItems($trashed);
        }

        $restored = 0;
        $failed = 0;
        $groups = [];
        $touched = [];

        $references = $rows->filter(fn ($row) => $row->model_type !== null && $existing->has((int) $row->merged_item_id));

        foreach ($references->groupBy(fn ($row) => $row->model_type . '|' . $row->model_id . '|' . $row->merged_item_id) as $group) {
            $first = $group->first();
            $expected = $group->count();
            $done = 0;

            try {
                $model = class_exists((string) $first->model_type) ? $first->model_type::query()->withoutGlobalScopes()->find($first->model_id) : null;

                if ($model) {
                    $merged = $existing[(int) $first->merged_item_id];
                    $only = [];

                    foreach ($group as $row) {
                        $only[$row->field . '|' . $row->locale] = true;
                    }

                    $done = count($this->extractor->rewrite(
                        $model,
                        [(int) $first->kept_item_id => (int) $merged->getKey()],
                        array_filter([(int) $merged->getKey() => (string) rescue(fn () => $merged->file()?->getUrl(), '', false)]),
                        $only,
                    ));

                    if ($done > 0) {
                        $this->writer->persist($model);
                        $this->indexer->reindex($model);
                        $this->noteProduct($model, $groups);
                        $touched[(int) $first->kept_item_id] = true;
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }

            $restored += $done;
            $failed += max(0, $expected - $done);
        }

        // Wat terug is, is geen samenvoeging meer; wat ontbrak blijft als spoor staan.
        DB::table(BrowseSchema::MERGES)->where('batch', $batch)->whereIn('merged_item_id', $existing->keys()->all())->delete();

        $this->afterRewriting(array_merge(array_keys($touched), $existing->keys()->all()), $groups);

        return [
            'restored' => $restored,
            'failed' => $failed,
            'items' => $existing->keys()->values()->all(),
            'missing' => $missing,
        ];
    }

    /** De laatste samenvoeging waarin dit item is opgegaan, of null. */
    public function batchOf(int $itemId): ?string
    {
        if (! BrowseSchema::hasMerges()) {
            return null;
        }

        $batch = DB::table(BrowseSchema::MERGES)->where('merged_item_id', $itemId)->orderByDesc('id')->value('batch');

        return is_string($batch) && $batch !== '' ? $batch : null;
    }

    /**
     * De modellen met een zekere verwijzing naar een van deze items.
     *
     * @param  array<int, int>  $itemIds
     * @param  array{0: string, 1: int}|null  $after  alleen de modellen na dit model, op volgorde van klasse en id
     */
    private function referencingModels(array $itemIds, ?array $after = null): \Illuminate\Support\Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        return DB::table(BrowseSchema::USAGES)
            ->whereIn('media_item_id', $itemIds)
            // Een gok wordt nooit herschreven; die rij blijft staan en houdt het duplicaat buiten de prullenbak.
            ->when(BrowseSchema::hasGuesses(), fn ($query) => $query->where('is_guess', false))
            ->when($after !== null, fn ($query) => $query->where(fn ($query) => $query
                ->where('model_type', '>', $after[0])
                ->orWhere(fn ($query) => $query->where('model_type', $after[0])->where('model_id', '>', $after[1]))))
            ->distinct()
            ->orderBy('model_type')
            ->orderBy('model_id')
            ->get(['model_type', 'model_id']);
    }

    /**
     * @return int het aantal omgezette verwijzingen in dit model
     */
    private function rewriteModel(string $type, int $modelId, array $replace, array $urls, string $batch, ?int $userId, array &$groups): int
    {
        if (! class_exists($type)) {
            return 0;
        }

        $model = $type::query()->withoutGlobalScopes()->find($modelId);

        if (! $model) {
            // Het model is echt weg: de rij is een wees en houdt niets tegen.
            DB::table(BrowseSchema::USAGES)->where('model_type', $type)->where('model_id', $modelId)->delete();

            return 0;
        }

        $rewritten = $this->extractor->rewrite($model, $replace, $urls);

        // De index zegt dat dit model verwijst, maar er was niets om te
        // zetten: de index loopt achter of het veld is anders dan verwacht.
        // Twijfel, dus de rij blijft staan en het duplicaat blijft bestaan.
        if ($rewritten === []) {
            return 0;
        }

        $this->writer->persist($model);

        DB::table(BrowseSchema::MERGES)->insert(array_map(fn (array $reference) => [
            'batch' => $batch,
            'kept_item_id' => $reference['to'],
            'merged_item_id' => $reference['from'],
            'model_type' => $type,
            'model_id' => $modelId,
            'field' => $reference['field'],
            'locale' => $reference['locale'],
            'user_id' => $userId,
            'created_at' => now(),
        ], $rewritten));

        // Zonder model-events loopt de index niet vanzelf mee.
        $this->indexer->reindex($model);
        $this->noteProduct($model, $groups);

        return count($rewritten);
    }

    /**
     * Een product bouwt zijn feed en zijn gecachete informatie in een eigen
     * taak. Zonder save() start die niet vanzelf: hier onthouden welke
     * productgroep hem na afloop één keer krijgt.
     */
    private function noteProduct(Model $model, array &$groups): void
    {
        $product = 'Dashed\\DashedEcommerceCore\\Models\\Product';
        $group = 'Dashed\\DashedEcommerceCore\\Models\\ProductGroup';

        if (is_a($model, $group)) {
            $groups[(int) $model->getKey()] = true;
        } elseif (is_a($model, $product) && is_numeric($model->getAttribute('product_group_id'))) {
            $groups[(int) $model->getAttribute('product_group_id')] = true;
        }
    }

    /**
     * @param  array<int, int>  $itemIds  de items waarvan de gebruikende modellen een verse cache moeten krijgen
     * @param  array<int, true>  $groups
     */
    private function afterRewriting(array $itemIds, array $groups): void
    {
        MediaFolderTree::forgetDuplicatesMemo();

        // De flush per site en het aanraken van de modellen, gebundeld.
        if ($itemIds !== []) {
            FlushMediaDependentCaches::queueMany(array_values(array_unique($itemIds)));
        }

        $job = 'Dashed\\DashedEcommerceCore\\Jobs\\UpdateProductInformationJob';
        $groupClass = 'Dashed\\DashedEcommerceCore\\Models\\ProductGroup';

        if ($groups === [] || ! class_exists($job) || ! class_exists($groupClass)) {
            return;
        }

        foreach ($groupClass::query()->withoutGlobalScopes()->whereKey(array_keys($groups))->get() as $group) {
            rescue(fn () => dispatch(new $job($group, false)));
        }
    }

    private function sourceOf(MediaItem $item): ?string
    {
        $source = $item->getAttribute('source') ?: $item->file()?->getCustomProperty('source');

        return is_string($source) ? $source : null;
    }

    /**
     * Alt-tekst, bijschrift en tags gaan mee waar het blijvende item ze mist.
     */
    private function inherit(MediaItem $keep, $others): void
    {
        $library = app(MediaLibrary::class);

        // Per taal: wat het blijvende item in een taal mist komt van de eerste die het daar wel heeft.
        foreach (['alt', 'caption'] as $field) {
            $have = \Dashed\DashedFiles\Classes\MediaTexts::of($keep, $field);
            $add = [];

            foreach ($others as $other) {
                $add += array_diff_key(\Dashed\DashedFiles\Classes\MediaTexts::of($other, $field), $have);
            }

            // Op de vers gelezen rij: wat intussen in een taal is ingevuld blijft staan.
            if ($add !== []) {
                $library->setTexts($keep, $field, $add, onlyEmpty: true);
            }
        }

        if (! BrowseSchema::hasRelations() || $others->isEmpty()) {
            return;
        }

        $names = DB::table(BrowseSchema::ITEM_TAG)
            ->join(BrowseSchema::TAGS, BrowseSchema::TAGS . '.id', '=', BrowseSchema::ITEM_TAG . '.media_tag_id')
            ->whereIn('media_item_id', $others->modelKeys())
            ->pluck(BrowseSchema::TAGS . '.name')
            ->unique()->values()->all();

        if ($names !== []) {
            app(MediaTagging::class)->setTags($keep, $names, 'add');
        }
    }
}
