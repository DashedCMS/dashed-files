<?php

namespace Dashed\DashedFiles\Classes;

use Throwable;
use Livewire\Component as LivewireComponent;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\Browse\MediaItemPayload;
use Dashed\DashedFiles\Filament\Forms\Components\MediaField;
use Filament\Support\Livewire\Partials\PartialsComponentHook;

/**
 * De gekozen items van alle mediavelden van één verzoek, in één keer
 * opgehaald. Velden melden hun id's aan (bij hydratie, en de eerste die
 * rendert veegt de rest van het formulier bijeen); de eerste die iets
 * opvraagt haalt alles wat is aangemeld in één query op, plus één voor de
 * mediarijen. Zonder dit kost een pagina met dertig velden dertig keer
 * dezelfde twee query's.
 *
 * Op het verzoek en niet in een static of een scoped binding: die overleven
 * onder Octane het verzoek en in tests de herstart.
 */
final class MediaFieldItems
{
    private const ATTRIBUTE = 'dashed-files.media-field-items';

    /** @var array<int, true> aangemeld, nog niet opgehaald */
    private array $pending = [];

    /** @var array<int, MediaItem|null> opgehaald; null is "bestaat niet (meer)" */
    private array $items = [];

    /** @var array<int, array<string, mixed>> */
    private array $payloads = [];

    /** @var array<int, bool> per id zonder bestand: bestaat het item zelf nog */
    private array $existsWithoutFile = [];

    /** @var array<string, true> Livewire-componenten waarvan het formulier al is afgelopen */
    private array $swept = [];

    public static function current(): self
    {
        $request = app()->bound('request') ? request() : null;
        $registry = $request?->attributes->get(self::ATTRIBUTE);

        if (! $registry instanceof self) {
            $registry = new self();
            $request?->attributes->set(self::ATTRIBUTE, $registry);
        }

        return $registry;
    }

    /**
     * @param  iterable<int, mixed>  $ids
     */
    public function announce(iterable $ids): void
    {
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0 && ! array_key_exists($id, $this->items)) {
                $this->pending[$id] = true;
            }
        }
    }

    /**
     * Meldt de id's van elk mediaveld in hetzelfde formulier aan, één keer
     * per Livewire-component per verzoek. Niet bij een gedeeltelijke render:
     * dan zou het aflopen van het formulier elk onderdeel opbouwen terwijl
     * er maar één veld getekend wordt.
     */
    public function sweep(MediaField $field): void
    {
        try {
            $livewire = $field->getLivewire();
            $key = $livewire instanceof LivewireComponent ? $livewire->getId() : spl_object_id($livewire);

            if (isset($this->swept[$key])) {
                return;
            }

            $this->swept[$key] = true;

            if ($livewire instanceof LivewireComponent) {
                $hook = app(PartialsComponentHook::class);
                $hook->setComponent($livewire);

                if (! $hook->isLackingPartialRendersToCoverAllCallsAndUpdates()) {
                    return;
                }
            }

            foreach ($field->getRootContainer()->getFlatFields() as $other) {
                if ($other instanceof MediaField) {
                    $this->announce($other->getIds());
                }
            }
        } catch (Throwable) {
            // Dan haalt elk veld alleen zijn eigen items op.
        }
    }

    public function item(int $id): ?MediaItem
    {
        if (! array_key_exists($id, $this->items)) {
            $this->pending[$id] = true;
            $this->load();
        }

        return $this->items[$id] ?? null;
    }

    /**
     * @return array<string, mixed>|null wat MediaItemPayload geeft, of null als het item niet bestaat
     */
    public function payload(int $id): ?array
    {
        $item = $this->item($id);

        if (! $item) {
            return null;
        }

        return $this->payloads[$id] ??= MediaItemPayload::for($item);
    }

    /**
     * Of het item zelf bestaat, ook als zijn bestand (even) ontbreekt.
     * item() kijkt alleen naar items met een bestand.
     */
    public function exists(int $id): bool
    {
        if ($this->item($id) !== null) {
            return true;
        }

        if (! array_key_exists($id, $this->existsWithoutFile)) {
            // In één keer voor alles wat tot nu toe ontbrak.
            $missing = array_keys(array_filter($this->items, fn (?MediaItem $item) => $item === null));
            $missing = array_values(array_diff($missing, array_keys($this->existsWithoutFile)));

            $found = array_flip(MediaItem::withoutGlobalScopes()->whereKey($missing)->toBase()->pluck('id')->map(fn ($found) => (int) $found)->all());

            foreach ($missing as $missingId) {
                $this->existsWithoutFile[$missingId] = isset($found[$missingId]);
            }
        }

        return $this->existsWithoutFile[$id] ?? false;
    }

    public function forget(int $id): void
    {
        unset($this->items[$id], $this->payloads[$id], $this->existsWithoutFile[$id]);
    }

    private function load(): void
    {
        $ids = array_keys($this->pending);
        $this->pending = [];

        if ($ids === []) {
            return;
        }

        // Ook uit de prullenbak: het veld toont zo'n item met een knop om het terug te zetten.
        $items = app(MediaLibrary::class)->findMany($ids, withTrashed: true);
        // De url en de miniatuur komen van de mediarij: één query voor alle items.
        $items->loadMissing('media');

        foreach ($ids as $id) {
            $this->items[$id] = $items->get($id);
        }
    }
}
