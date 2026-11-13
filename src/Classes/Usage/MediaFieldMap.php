<?php

namespace Dashed\DashedFiles\Classes\Usage;

use Throwable;
use ReflectionProperty;
use Illuminate\Support\Facades\Log;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Builder\Block;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Filament\Forms\Components\MediaField;

/**
 * Waar in de data van een blok een media-id of rijke tekst staat, afgeleid
 * uit de blokdefinities zelf en niet geraden op de naam van een sleutel.
 *
 * Een boom is een array van veldnaam naar knoop:
 *   ['k' => 'media']                          een mediaveld
 *   ['k' => 'rich']                           een rijke-tekstveld
 *   ['k' => 'repeater', 'fields' => boom]     items met elk dezelfde velden
 *   ['k' => 'group', 'fields' => boom]        een wrapper met een eigen statepad
 *   ['k' => 'builder', 'blocks' => [type => boom]]
 *   ['k' => 'plain']                          een ander veld (tekst, getal, een FileUpload)
 *
 * Elk veld met een naam staat erin, ook de gewone: een sleutel in de data
 * die in geen enkel schema voorkomt valt daardoor op, en alleen daar geldt
 * het ruime vangnet van de extractor.
 *
 * Aangemeld als `scoped`: één keer opgebouwd per verzoek of wachtrijtaak.
 */
class MediaFieldMap
{
    // Zo vaak mag hetzelfde veld in zijn eigen voorouders voorkomen voordat
    // het als een kring telt (een schema dat zichzelf via een closure opnieuw opbouwt).
    private const MAX_SAME_ANCESTOR = 6;

    // Deze lijsten van cms()->builder() zijn geen custom-blokken.
    private const NOT_CUSTOM_BLOCKS = ['blocks', 'plugins', 'publishOnUpdate', 'builderBlockClasses', 'editor'];

    /** @var array<string, array>|null */
    private ?array $blocks = null;

    private ?array $custom = null;

    /** @var array<string, string> bloktype (of lijstnaam) => waarom het schema niet te bouwen was */
    private array $failed = [];

    private ?object $looseLivewire = null;

    /**
     * Elk bloktype uit de pagina-, mail- en nieuwsbriefblokken.
     *
     * @return array<string, array> bloktype => boom
     */
    public function blocks(): array
    {
        if ($this->blocks !== null) {
            return $this->blocks;
        }

        $map = [];

        foreach ($this->blockDefinitions() as $block) {
            $this->addBlock($map, $block);
        }

        return $this->blocks = $map;
    }

    /**
     * De velden van de custom-blokken: de vereniging van elke
     * `cms()->builder('<naam>')`-lijst die uit formuliervelden bestaat. Welke
     * lijst bij welk model hoort is hier niet te weten, en hoeft ook niet:
     * een veld dat in de data niet voorkomt levert niets op.
     */
    public function customFields(): array
    {
        if ($this->custom !== null) {
            return $this->custom;
        }

        $tree = [];

        foreach ($this->builderLists() as $name => $components) {
            if (in_array($name, self::NOT_CUSTOM_BLOCKS, true) || ! is_array($components)) {
                continue;
            }

            $components = array_filter($components, fn ($component) => is_object($component) && ! $component instanceof Block && method_exists($component, 'getDefaultChildComponents'));

            if ($components === []) {
                continue;
            }

            try {
                $tree = $this->merge($tree, $this->walk(array_values($components), []));
            } catch (Throwable $exception) {
                $this->fail('list:' . $name, $exception);
            }
        }

        return $this->custom = $tree;
    }

    public function forget(): void
    {
        $this->blocks = $this->custom = null;
        $this->failed = [];
    }

    /**
     * De bloktypen waarvan het schema niet te bouwen was. Hun data wordt met
     * het ruime vangnet gelezen: veilig voor "ongebruikt", nooit herschreven.
     *
     * @return array<string, string> bloktype => reden
     */
    public function unbuildable(): array
    {
        $this->blocks();
        $this->customFields();

        return $this->failed;
    }

    private function fail(string $type, Throwable $exception): void
    {
        $this->failed[$type] = mb_substr($exception->getMessage(), 0, 200);

        // Een waarschuwing en geen debugregel: voor dit blok is "waar
        // gebruikt" een gok tot het schema zonder record te bouwen is.
        Log::warning('Media usage: the schema of [' . $type . '] could not be built; its media references are guessed. ' . $exception->getMessage());
    }

    /**
     * @return iterable<int, Block>
     */
    protected function blockDefinitions(): iterable
    {
        try {
            cms()->activateBuilderBlockClasses();
        } catch (Throwable $exception) {
            Log::debug('Media usage: builder block classes could not be activated: ' . $exception->getMessage());
        }

        foreach ((array) rescue(fn () => cms()->builder('blocks'), [], false) as $block) {
            if ($block instanceof Block) {
                yield $block;
            }
        }

        // De nieuwsbriefblokken zijn dezelfde klassen, gefilterd op context.
        foreach ((array) rescue(fn () => cms()->emailBlocks(), [], false) as $class) {
            try {
                $block = is_string($class) && method_exists($class, 'filamentBlock') ? $class::filamentBlock() : null;
            } catch (Throwable $exception) {
                $this->fail('mail:' . (is_string($class) ? class_basename($class) : '?'), $exception);

                continue;
            }

            if ($block instanceof Block) {
                yield $block;
            }
        }

        // Een Builder buiten cms()->builder('blocks') om (bijvoorbeeld in een
        // custom-bloklijst) draagt zijn eigen blokken; die tellen ook mee.
        foreach ($this->builderLists() as $name => $components) {
            if ($name === 'blocks' || ! is_array($components)) {
                continue;
            }

            foreach ($components as $component) {
                if ($component instanceof Block) {
                    yield $component;
                }
            }
        }
    }

    /**
     * @return array<string, mixed> elke lijst van cms()->builder()
     */
    protected function builderLists(): array
    {
        try {
            $property = new ReflectionProperty(cms(), 'builders');

            return (array) $property->getValue();
        } catch (Throwable) {
            return [];
        }
    }

    private function addBlock(array &$map, Block $block): void
    {
        $type = '?';

        try {
            $type = (string) $block->getName();
            $tree = $this->walk($this->children($block), ['block:' . $type]);
        } catch (Throwable $exception) {
            // Een schema dat een record of een container nodig heeft: dit blok
            // valt terug op het vangnet, de rest loopt door.
            $this->fail($type, $exception);

            return;
        }

        // Een latere definitie onder dezelfde naam die wel te bouwen is telt.
        unset($this->failed[$type]);

        $map[$type] = isset($map[$type]) ? $this->merge($map[$type], $tree) : $tree;
    }

    /**
     * @param  array<int, mixed>  $components
     * @param  array<int, string>  $ancestors
     */
    private function walk(array $components, array $ancestors): array
    {
        $tree = [];

        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            $name = $this->nameOf($component);

            if ($name !== null && $this->isMediaField($component)) {
                $tree[$name] = ['k' => 'media'];

                continue;
            }

            // Een FileUpload bewaart een pad en geen media-id.
            if ($component instanceof FileUpload) {
                if ($name !== null) {
                    $tree[$name] ??= ['k' => 'plain'];
                }

                continue;
            }

            if ($name !== null && $component instanceof RichEditor) {
                $tree[$name] = ['k' => 'rich'];

                continue;
            }

            $signature = $component::class . ':' . ($name ?? '');

            if (count(array_keys($ancestors, $signature, true)) >= self::MAX_SAME_ANCESTOR) {
                Log::debug('Media usage: schema walk stopped at a cycle on [' . $signature . '].');

                continue;
            }

            if ($name !== null && $component instanceof Builder) {
                $blocks = [];

                foreach ($this->children($component) as $block) {
                    if (! $block instanceof Block) {
                        continue;
                    }

                    $type = (string) $block->getName();

                    // Een blok dat zichzelf bevat: de data daarbinnen wordt
                    // met de boom van het bovenliggende type gelezen.
                    if (in_array('block:' . $type, $ancestors, true)) {
                        continue;
                    }

                    try {
                        $blocks[$type] = $this->walk($this->children($block), [...$ancestors, $signature, 'block:' . $type]);
                    } catch (Throwable $exception) {
                        $this->fail($type, $exception);
                    }
                }

                $tree[$name] = ['k' => 'builder', 'blocks' => $blocks];

                continue;
            }

            foreach ($this->linkKeys($component) as $linkKey) {
                $tree[$linkKey] ??= ['k' => 'plain'];
            }

            // Per component: één kind dat niet te lezen is maakt niet het hele
            // blok onbruikbaar. Het staat wel op de lijst, want wat eronder
            // hangt is dan alleen via het vangnet te vinden.
            try {
                $children = $this->children($component);
            } catch (Throwable $exception) {
                $block = collect($ancestors)->first(fn (string $ancestor) => str_starts_with($ancestor, 'block:'));
                $this->fail(($block === null ? '' : substr($block, 6) . ' > ') . class_basename($component) . ($name === null ? '' : ' [' . $name . ']'), $exception);

                if ($name !== null) {
                    $tree[$name] ??= ['k' => 'plain'];
                }

                continue;
            }

            if ($children === []) {
                // Een gewoon veld: bekend, dus geen sleutel "buiten elk schema".
                if ($name !== null) {
                    $tree[$name] ??= ['k' => 'plain'];
                }

                continue;
            }

            $fields = $this->walk($children, [...$ancestors, $signature]);

            if ($name !== null && $component instanceof Repeater) {
                $tree[$name] = isset($tree[$name]['fields'])
                    ? ['k' => 'repeater', 'fields' => $this->merge($tree[$name]['fields'], $fields)]
                    : ['k' => 'repeater', 'fields' => $fields];

                continue;
            }

            $statePath = $this->statePathOf($component);

            if ($statePath !== null) {
                $tree[$statePath] = ['k' => 'group', 'fields' => isset($tree[$statePath]['fields']) ? $this->merge($tree[$statePath]['fields'], $fields) : $fields];

                continue;
            }

            if ($name !== null) {
                // Een veld met eigen onderdelen dat geen Repeater of Builder is
                // (een KeyValue, een eigen component): zijn inhoud is onbekend.
                $tree[$name] ??= ['k' => 'plain'];

                continue;
            }

            // Een lay-outwrapper zonder eigen pad: zijn velden staan op dit niveau.
            $tree = $this->merge($tree, $fields);
        }

        return $tree;
    }

    private function isMediaField(object $component): bool
    {
        return $component instanceof MediaField || is_a($component, MediaItem::LEGACY_PICKER);
    }

    /**
     * De kinderen van een component, zonder dat het aan een formulier hangt.
     * Een schema dat in een closure de staat leest (`fn (Get $get) => ...`,
     * zoals de linkvelden van linkHelper()) heeft daar een container voor
     * nodig: die krijgt bij een tweede poging een losse, lege container, zodat
     * `$get()` null geeft in plaats van een fout.
     *
     * @return array<int, mixed>
     */
    private function children(object $component): array
    {
        if (! method_exists($component, 'getDefaultChildComponents')) {
            return [];
        }

        try {
            // getChildComponents() gooit zonder container; dit meestal niet.
            $children = $component->getDefaultChildComponents();
        } catch (Throwable $exception) {
            $loose = $this->withLooseContainer($component);

            if ($loose === null) {
                throw $exception;
            }

            try {
                $children = $loose->getDefaultChildComponents();
            } catch (Throwable) {
                // De eerste fout zegt het meest.
                throw $exception;
            }
        }

        if (is_object($children) && method_exists($children, 'getComponents')) {
            $children = $children->getComponents(withHidden: true);
        }

        return is_array($children) ? array_values($children) : [];
    }

    /**
     * Een kopie van het component in een leeg formulier op een lege
     * Livewire-pagina. Alleen om het schema te kunnen lezen; er wordt niets
     * mee gerenderd of bewaard.
     */
    private function withLooseContainer(object $component): ?object
    {
        if (! method_exists($component, 'container')) {
            return null;
        }

        try {
            $this->looseLivewire ??= new class () extends \Livewire\Component implements \Filament\Schemas\Contracts\HasSchemas {
                use \Filament\Schemas\Concerns\InteractsWithSchemas;

                public array $data = [];
            };

            $copy = clone $component;
            $copy->container(\Filament\Schemas\Schema::make($this->looseLivewire)->statePath('data'));

            return $copy;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * De groep van linkHelper() toont alleen de keuzelijst van het gekozen
     * linktype, dus zonder staat heeft hij geen kinderen. De sleutels die hij
     * kan bewaren (`<prefix>_<routemodel>_id`) zijn wel bekend: zonder deze
     * regel zouden die link-id's "buiten elk schema" vallen en als gegokte
     * mediaverwijzing tellen.
     *
     * @return array<int, string>
     */
    private function linkKeys(object $component): array
    {
        try {
            $reflection = new \ReflectionObject($component);

            if (! $reflection->hasProperty('linkPrefix') || ! $reflection->hasProperty('routeModelKeys')) {
                return [];
            }

            $prefix = $reflection->getProperty('linkPrefix')->getValue($component);
            $keys = $reflection->getProperty('routeModelKeys')->getValue($component);

            return is_string($prefix) && is_array($keys)
                ? array_map(fn ($key) => $prefix . '_' . $key . '_id', array_filter($keys, 'is_string'))
                : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function nameOf(object $component): ?string
    {
        if (! method_exists($component, 'getName')) {
            return null;
        }

        try {
            $name = $component->getName();
        } catch (Throwable) {
            return null;
        }

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function statePathOf(object $component): ?string
    {
        if (method_exists($component, 'getName') || ! method_exists($component, 'getStatePath')) {
            return null;
        }

        try {
            $path = $component->getStatePath(isAbsolute: false);
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function merge(array $a, array $b): array
    {
        foreach ($b as $name => $node) {
            if (! isset($a[$name]) || ($a[$name]['k'] ?? null) !== ($node['k'] ?? null)) {
                // Bij twee soorten onder dezelfde naam wint een mediaveld (dat
                // telt alleen cijfers en kan dus geen kwaad), en verliest een
                // gewoon veld van alles.
                $keep = isset($a[$name]) && (($a[$name]['k'] ?? null) === 'media' || ($node['k'] ?? null) === 'plain');
                $a[$name] = $keep ? $a[$name] : $node;

                continue;
            }

            if (isset($node['fields'])) {
                $a[$name]['fields'] = $this->merge($a[$name]['fields'] ?? [], $node['fields']);
            }

            if (isset($node['blocks'])) {
                foreach ($node['blocks'] as $type => $tree) {
                    $a[$name]['blocks'][$type] = isset($a[$name]['blocks'][$type]) ? $this->merge($a[$name]['blocks'][$type], $tree) : $tree;
                }
            }
        }

        return $a;
    }
}
