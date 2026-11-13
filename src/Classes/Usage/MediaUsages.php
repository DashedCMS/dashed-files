<?php

namespace Dashed\DashedFiles\Classes\Usage;

use Throwable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Database\Eloquent\SoftDeletes;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Leest de gebruiksindex voor de beheerpagina.
 */
class MediaUsages
{
    /**
     * @return array<int, array{label: string, title: string, field: string, locale: ?string, url: ?string, trashed: bool, guess: bool}>
     */
    public function of(int $itemId, int $limit = 100): array
    {
        if ($itemId <= 0 || ! BrowseSchema::hasUsages()) {
            return [];
        }

        $rows = DB::table(BrowseSchema::USAGES)
            ->where('media_item_id', $itemId)
            ->orderBy('model_type')->orderBy('model_id')->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->get(BrowseSchema::hasGuesses() ? ['model_type', 'model_id', 'field', 'locale', 'is_guess'] : ['model_type', 'model_id', 'field', 'locale']);

        $models = $this->load($rows->groupBy('model_type')->map(fn ($group) => $group->pluck('model_id')->unique()->all())->all());
        $usages = [];

        foreach ($rows as $row) {
            $model = $models[$row->model_type][(int) $row->model_id] ?? null;

            // Het model is weg zonder dat de index het zag; de nachtronde ruimt de rij op.
            if (! $model) {
                continue;
            }

            // Een gok: aangenomen op de naam van een sleutel of op een getal dat
            // toevallig een media-id is. De interface zegt er "vermoedelijk" bij.
            $usages[] = $this->describe($model, (string) $row->field, (string) $row->locale) + ['guess' => (bool) ($row->is_guess ?? false)];
        }

        // Als poster van een video of pdf: geen rij in de index, de kolom zelf is de bron.
        foreach ($this->posterUsers($itemId, max(1, $limit - count($usages))) as $user) {
            $usages[] = [
                'label' => __('Mediabibliotheek'),
                'title' => (string) ($user->file()?->file_name ?: '#' . $user->getKey()),
                'field' => __('Poster'),
                'locale' => null,
                'url' => null,
                'trashed' => $user->trashed(),
                'guess' => false,
            ];
        }

        return $usages;
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return array<int, int> item-id => aantal verwijzingen
     */
    public function counts(array $itemIds): array
    {
        if ($itemIds === [] || ! BrowseSchema::hasUsages()) {
            return [];
        }

        $usages = DB::table(BrowseSchema::USAGES)
            ->whereIn('media_item_id', $itemIds)
            ->groupBy('media_item_id')
            ->selectRaw('media_item_id as item_id, count(*) as aggregate');

        // Een afbeelding die een video of pdf als poster gebruikt is in
        // gebruik, ook al staat ze in geen enkel veld van de site. In
        // dezelfde query: de lijst vraagt dit per pagina.
        if (BrowseSchema::hasPreview()) {
            $usages->unionAll(DB::table((new MediaItem())->getTable())
                ->whereIn('poster_item_id', $itemIds)
                ->groupBy('poster_item_id')
                ->selectRaw('poster_item_id as item_id, count(*) as aggregate'));
        }

        $counts = [];

        foreach ($usages->get() as $row) {
            $counts[(int) $row->item_id] = ($counts[(int) $row->item_id] ?? 0) + (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * De voorwaarde "er is een item dat deze rij als poster gebruikt", voor
     * een whereExists op de tabel van de bibliotheek. Eén definitie voor het
     * filter, de slimme map Ongebruikt en het legen van de prullenbak.
     */
    public static function posterUseExists($sub, string $table): void
    {
        $sub->selectRaw('1')->from($table . ' as poster_users')->whereColumn('poster_users.poster_item_id', $table . '.id');
    }

    /**
     * @return \Illuminate\Support\Collection<int, MediaItem>
     */
    private function posterUsers(int $itemId, int $limit)
    {
        if (! BrowseSchema::hasPreview()) {
            return collect();
        }

        return rescue(fn () => MediaItem::withoutGlobalScopes()->where('poster_item_id', $itemId)->orderBy('id')->limit($limit)->get(), collect(), false);
    }

    /**
     * Van hoeveel van deze items de index minstens één verwijzing kent.
     *
     * @param  array<int, int>  $itemIds
     */
    public function usedAmong(array $itemIds): int
    {
        $used = 0;

        foreach (array_chunk($itemIds, 1000) as $chunk) {
            $used += count($this->counts($chunk));
        }

        return $used;
    }

    /**
     * @param  array<string, array<int, int>>  $idsPerType
     * @return array<string, array<int, Model>>
     */
    private function load(array $idsPerType): array
    {
        $models = [];

        foreach ($idsPerType as $type => $ids) {
            if (! class_exists($type)) {
                continue;
            }

            try {
                $query = $type::query()->withoutGlobalScopes();

                if ($type === MediaUsageRegistry::SETTING_MODEL) {
                    $query->select(['id', 'name', 'site_id', 'locale']);
                }

                $models[$type] = $query->whereKey($ids)->get()->keyBy(fn (Model $model) => (int) $model->getKey())->all();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $models;
    }

    /**
     * @return array{label: string, title: string, field: string, locale: ?string, url: ?string, trashed: bool}
     */
    private function describe(Model $model, string $field, string $locale): array
    {
        // Een custom-blok of metadata hoort bij een pagina of product: dat is
        // wat een beheerder herkent en waar hij het kan aanpassen.
        $owner = $this->ownerOf($model) ?? $model;

        return [
            'label' => $model::class === MediaUsageRegistry::SETTING_MODEL ? __('Instelling') : $this->labelOf($owner),
            'title' => $model::class === MediaUsageRegistry::SETTING_MODEL ? (string) $model->getAttribute('name') : $this->titleOf($owner),
            'field' => $owner === $model ? $field : class_basename($model) . ': ' . $field,
            'locale' => $locale === '' ? null : $locale,
            'url' => $model::class === MediaUsageRegistry::SETTING_MODEL ? null : $this->editUrl($owner),
            'trashed' => in_array(SoftDeletes::class, class_uses_recursive($owner), true) && rescue(fn () => $owner->trashed(), false, false),
        ];
    }

    private function ownerOf(Model $model): ?Model
    {
        foreach (['blockable', 'metadatable'] as $morph) {
            $type = $model->getAttribute($morph . '_type');
            $id = $model->getAttribute($morph . '_id');

            if (! is_string($type) || $id === null) {
                continue;
            }

            $class = Relation::getMorphedModel($type) ?? $type;

            if (class_exists($class)) {
                return rescue(fn () => $class::query()->withoutGlobalScopes()->find($id), null, false);
            }
        }

        return null;
    }

    private function labelOf(Model $model): string
    {
        return (string) rescue(function () use ($model) {
            $resource = Filament::getPanel('dashed')->getModelResource($model);

            return $resource ? $resource::getModelLabel() : null;
        }, null, false) ?: class_basename($model);
    }

    private function titleOf(Model $model): string
    {
        $trash = 'Dashed\\DashedCore\\Classes\\Trash\\Trash';

        if (class_exists($trash)) {
            return (string) $trash::title($model);
        }

        foreach (['name', 'title', 'slug'] as $attribute) {
            $value = rescue(fn () => $model->{$attribute}, null, false);
            $value = is_array($value) ? reset($value) : $value;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '#' . $model->getKey();
    }

    private function editUrl(Model $model): ?string
    {
        // Een ontbrekende resource of route mag de lijst niet breken.
        return rescue(function () use ($model) {
            $resource = Filament::getPanel('dashed')->getModelResource($model);

            return $resource && $resource::hasPage('edit') ? $resource::getUrl('edit', ['record' => $model]) : null;
        }, null, false);
    }
}
