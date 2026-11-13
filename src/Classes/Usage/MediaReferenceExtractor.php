<?php

namespace Dashed\DashedFiles\Classes\Usage;

use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use Dashed\DashedFiles\Models\MediaItem;

/**
 * Leest uit één model elke verwijzing naar een media-item, en kan dezelfde
 * wandeling gebruiken om een id door een ander te vervangen (samenvoegen
 * van dubbele bestanden) of het opgeslagen adres van een ingesloten
 * afbeelding bij te werken (na het vervangen van een bestand).
 *
 * Een verwijzing is zeker (het schema zegt dat daar een mediaveld of een
 * media-node staat) of een gok. Een gok telt mee voor "in gebruik" en wordt
 * nooit herschreven.
 */
class MediaReferenceExtractor
{
    // Sleutels die naar hun naam een mediaveld zijn. Een cijferwaarde
    // daaronder telt als gok, ook als er geen item met dat id bestaat.
    public const GUESS_KEYS = ['image', 'images', 'media', 'logo', 'avatar'];

    // Dit blok verwijst naar een GlobalBlock en niet naar media; het
    // GlobalBlock zelf wordt geïndexeerd.
    public const SKIP_BLOCKS = ['globalBlock'];

    public const MAX_FIELD_LENGTH = 191;

    // Zoveel gokken per model: het ruime vangnet kan in een blok vol getallen
    // (een prijstabel) veel toevalstreffers geven.
    public const MAX_GUESSES = 200;

    private const MAX_REFERENCES = 5000;

    private const MAX_CANDIDATES = 2000;

    /** @var array<string, array{media_item_id: int, field: string, locale: string, guess: bool}> */
    private array $found = [];

    /** @var array<int, array{0: int, 1: string, 2: string}> een getal buiten elk schema: telt als er een item met dat id bestaat */
    private array $candidates = [];

    private int $guesses = 0;

    /** @var array<int, int>|null oud id => nieuw id, alleen tijdens rewrite() */
    private ?array $replace = null;

    /** @var array<string, true>|null alleen deze precieze plekken (`pad|taal`), voor ongedaan maken */
    private ?array $only = null;

    /** @var array<int, string> id => url van het huidige bestand */
    private array $urls = [];

    /** @var bool alleen het adres bijwerken, het id blijft */
    private bool $sourcesOnly = false;

    /** @var array<int, array{from: int, to: int, field: string, locale: string}> wat rewrite() omzette */
    private array $rewritten = [];

    /** @var array<string, int> teller per pad voor nodes in rijke tekst */
    private array $occurrence = [];

    private int $changed = 0;

    public function __construct(private readonly MediaFieldMap $map)
    {
    }

    /**
     * @return array<int, array{media_item_id: int, field: string, locale: string, guess: bool}>
     */
    public function extract(Model $model): array
    {
        $this->reset();

        foreach ($this->attributesOf($model) as $attribute => $kind) {
            foreach ($this->valuesPerLocale($model, $attribute, $kind) as $locale => $value) {
                $this->read($value, $kind, $attribute, (string) $locale);
            }
        }

        $this->resolveCandidates();

        return array_values($this->found);
    }

    /**
     * Vervangt in elk aangemeld attribuut van het model de id's uit $replace
     * en zet de nieuwe waarden op het model, zonder op te slaan. Een gegokte
     * verwijzing blijft staan.
     *
     * @param  array<int, int>  $replace  oud id => nieuw id
     * @param  array<int, string>  $urls  nieuw id => url van het origineel
     * @param  array<string, true>|null  $only  alleen deze precieze plekken (`pad|taal`)
     * @return array<int, array{from: int, to: int, field: string, locale: string}> elke omgezette verwijzing, met haar precieze plek
     */
    public function rewrite(Model $model, array $replace, array $urls = [], ?array $only = null): array
    {
        $this->reset();
        $this->replace = $replace;
        $this->urls = $urls;
        $this->only = $only;

        try {
            $this->apply($model);

            return $this->rewritten;
        } finally {
            $this->reset();
        }
    }

    /**
     * Zet in rijke tekst het opgeslagen adres van de media-nodes van deze
     * items op het huidige adres. Het id blijft gelijk.
     *
     * @param  array<int, string>  $urls  id => url van het huidige bestand
     * @return int het aantal bijgewerkte adressen
     */
    public function rewriteSources(Model $model, array $urls): int
    {
        $this->reset();
        $this->replace = [];
        $this->urls = $urls;
        $this->sourcesOnly = true;

        try {
            $this->apply($model);

            return $this->changed;
        } finally {
            $this->reset();
        }
    }

    /**
     * @return array<string, string> attribuut => soort, voor dit model
     */
    public function attributesOf(Model $model): array
    {
        $class = MediaUsageRegistry::resolve($model::class);

        if ($class === null) {
            return [];
        }

        if ($class === MediaUsageRegistry::SETTING_MODEL) {
            return MediaUsageRegistry::isSetting($model->getAttribute('name')) ? ['value' => 'id'] : [];
        }

        $attributes = [];

        foreach (MediaUsageRegistry::for($class) as $attribute => $kind) {
            $when = MediaUsageRegistry::conditionFor($class, $attribute);

            if ($when === null || rescue(fn () => (bool) $when($model), false, false)) {
                $attributes[$attribute] = $kind;
            }
        }

        return $attributes;
    }

    /**
     * De naam waaronder een verwijzing van dit model in de index komt.
     */
    public function fieldName(Model $model, string $field): string
    {
        // Een instelling staat onder zijn sleutel (site_logo), niet onder `value`.
        if (MediaUsageRegistry::resolve($model::class) === MediaUsageRegistry::SETTING_MODEL) {
            return mb_substr((string) $model->getAttribute('name') . substr($field, strlen('value')), 0, self::MAX_FIELD_LENGTH);
        }

        return mb_substr($field, 0, self::MAX_FIELD_LENGTH);
    }

    private function reset(): void
    {
        $this->found = [];
        $this->candidates = [];
        $this->guesses = 0;
        $this->replace = null;
        $this->only = null;
        $this->urls = [];
        $this->sourcesOnly = false;
        $this->rewritten = [];
        $this->occurrence = [];
        $this->changed = 0;
    }

    /** Loopt elk attribuut af en zet wat veranderde terug op het model. */
    private function apply(Model $model): void
    {
        foreach ($this->attributesOf($model) as $attribute => $kind) {
            $translatable = $this->isTranslatable($model, $attribute);

            foreach ($this->valuesPerLocale($model, $attribute, $kind) as $locale => $value) {
                $before = $this->changed;
                $new = $this->read($value, $kind, $attribute, (string) $locale);

                if ($this->changed === $before) {
                    continue;
                }

                if ($translatable && $locale !== '') {
                    // Een vertaling die JSON als tekst droeg krijgt weer tekst.
                    $raw = $model->getTranslations($attribute)[$locale] ?? null;
                    $model->setTranslation($attribute, $locale, is_string($raw) && is_array($new) ? json_encode($new) : $new);
                } elseif ($locale !== '' && ! $this->isSetting($model) && is_array($current = $this->decoded($model->getAttribute($attribute))) && array_key_exists($locale, $current)) {
                    // Een oude taal-array in een kolom die niet (meer) vertaalbaar is.
                    $current[$locale] = $new;
                    $model->setAttribute($attribute, $this->encodeLike($model, $attribute, $current));
                } else {
                    $model->setAttribute($attribute, $this->encodeLike($model, $attribute, $new));
                }
            }
        }
    }

    private function isSetting(Model $model): bool
    {
        return MediaUsageRegistry::resolve($model::class) === MediaUsageRegistry::SETTING_MODEL;
    }

    /**
     * @return array<string, mixed> taal ('' zonder taal) => waarde
     */
    private function valuesPerLocale(Model $model, string $attribute, string $kind): array
    {
        if ($this->isTranslatable($model, $attribute)) {
            $translations = rescue(fn () => $model->getTranslations($attribute), null, false);

            if (is_array($translations)) {
                return array_map(fn ($value) => $this->decoded($value), $translations);
            }
        }

        $value = $this->decoded($model->getAttribute($attribute));

        // Een instelling per taal draagt zijn taal op de rij.
        if ($this->isSetting($model)) {
            return [(string) ($model->getAttribute('locale') ?? '') => $value];
        }

        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        // Oude data: een taal-array in een kolom die niet vertaalbaar is.
        if (is_array($value) && $this->looksPerLocale($value)) {
            return $value;
        }

        return ['' => $value];
    }

    private function read(mixed $value, string $kind, string $path, string $locale): mixed
    {
        return match ($kind) {
            'id', 'ids' => $this->readIds($value, $path, $locale),
            'blocks' => is_array($value) ? $this->readBlocks($value, $this->map->blocks(), $path, $locale) : $value,
            'custom_blocks' => is_array($value) ? $this->readFields($value, $this->map->customFields(), $path, $locale) : $value,
            'richtext' => $this->readRich($value, $path, $locale),
            default => $value,
        };
    }

    private function readIds(mixed $value, string $path, string $locale): mixed
    {
        if (! is_array($value)) {
            return $this->reference($value, $path, $locale, false, $path);
        }

        foreach ($value as $key => $entry) {
            // Alleen losse waarden: een genest array in een lijst id's is geen id.
            if (! is_array($entry)) {
                $value[$key] = $this->reference($entry, $path, $locale, false, $path . '.' . $key);
            }
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $blocks  [{type, data}]
     * @param  array<string, array>  $types
     */
    private function readBlocks(array $blocks, array $types, string $path, string $locale): array
    {
        foreach ($blocks as $index => $block) {
            if (! is_array($block) || ! is_array($block['data'] ?? null)) {
                continue;
            }

            $type = is_string($block['type'] ?? null) ? $block['type'] : '';

            if (in_array($type, self::SKIP_BLOCKS, true)) {
                continue;
            }

            // Het geneste type eerst, dan de algemene lijst (een blok dat zichzelf bevat).
            $tree = $types[$type] ?? $this->map->blocks()[$type] ?? null;

            if ($tree === null) {
                // Onbekend of niet te bouwen: alleen gokken, niets wijzigen.
                $this->guess($block['data'], $path . '.' . $index, $locale, 0);

                continue;
            }

            $blocks[$index]['data'] = $this->readFields($block['data'], $tree, $path . '.' . $index, $locale);
        }

        return $blocks;
    }

    private function readFields(array $data, array $tree, string $path, string $locale): array
    {
        foreach ($data as $name => $value) {
            $here = $path . '.' . $name;
            $node = $tree[$name] ?? null;

            if ($node === null) {
                // Een sleutel buiten elk schema: gokken.
                $this->guess([$name => $value], $path, $locale, 0);

                continue;
            }

            $data[$name] = match ($node['k'] ?? null) {
                'media' => $this->readIds($value, $here, $locale),
                'rich' => $this->readRich($value, $here, $locale),
                'group' => is_array($value) ? $this->readFields($value, $node['fields'] ?? [], $here, $locale) : $value,
                'repeater' => is_array($value) ? $this->readItems($value, $node['fields'] ?? [], $here, $locale) : $value,
                'builder' => is_array($value) ? $this->readBlocks($value, $node['blocks'] ?? [], $here, $locale) : $value,
                default => $value,
            };
        }

        return $data;
    }

    private function readItems(array $items, array $fields, string $path, string $locale): array
    {
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $items[$key] = $this->readFields($item, $fields, $path . '.' . $key, $locale);
            }
        }

        return $items;
    }

    /**
     * Het vangnet voor data zonder schema. Twee soorten gok: een sleutel
     * die naar zijn naam een mediaveld is (elke cijferwaarde telt), en elk
     * ander geheel getal, dat pas telt als er een media-item met dat id
     * bestaat. Dat laatste geeft valse "in gebruik"-meldingen (een aantal dat
     * toevallig een id is), en dat is de veilige kant: het houdt een bestand
     * uit "Ongebruikt". Leest alleen; een gok wordt nooit herschreven.
     */
    private function guess(array $data, string $path, string $locale, int $depth): void
    {
        if ($depth > 12 || $this->replace !== null) {
            return;
        }

        foreach ($data as $key => $value) {
            $here = $path . '.' . $key;

            if (is_array($value)) {
                if (is_string($key) && $this->isGuessKey($key) && array_is_list($value)) {
                    foreach ($value as $entry) {
                        is_array($entry) ? $this->guess($entry, $here, $locale, $depth + 1) : $this->reference($entry, $here, $locale, true);
                    }

                    continue;
                }

                $this->guess($value, $here, $locale, $depth + 1);

                continue;
            }

            if (is_string($key) && $this->isGuessKey($key)) {
                $this->reference($value, $here, $locale, true);

                continue;
            }

            if (($id = $this->idOf($value)) !== null && count($this->candidates) < self::MAX_CANDIDATES) {
                $this->candidates[] = [$id, $here, $locale];
            }
        }
    }

    /** Van de losse getallen blijven alleen die over waar een media-item bij bestaat. */
    private function resolveCandidates(): void
    {
        if ($this->candidates === []) {
            return;
        }

        $ids = array_values(array_unique(array_column($this->candidates, 0)));
        $existing = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            // Ook wat in de prullenbak ligt of (even) geen bestand heeft.
            foreach (rescue(fn () => MediaItem::withoutGlobalScopes()->toBase()->whereIn('id', $chunk)->pluck('id')->all(), [], false) as $id) {
                $existing[(int) $id] = true;
            }
        }

        foreach ($this->candidates as [$id, $path, $locale]) {
            if (isset($existing[$id])) {
                $this->reference($id, $path, $locale, true);
            }
        }

        $this->candidates = [];
    }

    private function isGuessKey(string $key): bool
    {
        $key = strtolower($key);

        return in_array($key, self::GUESS_KEYS, true) || str_ends_with($key, '_image') || str_ends_with($key, '-image');
    }

    private function readRich(mixed $value, string $path, string $locale): mixed
    {
        if (is_array($value)) {
            return $this->readTipTap($value, $path, $locale, 0);
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        $trimmed = ltrim($value);

        if ($trimmed !== '' && $trimmed[0] === '{' && is_array($document = json_decode($value, true))) {
            $new = $this->readTipTap($document, $path, $locale, 0);

            return $new === $document ? $value : json_encode($new);
        }

        return str_contains($value, 'data-media-id') ? $this->readHtml($value, $path, $locale) : $value;
    }

    private function readTipTap(array $node, string $path, string $locale, int $depth): array
    {
        if ($depth > 200) {
            return $node;
        }

        if (($node['type'] ?? null) === 'mediaEmbed' && is_array($node['attrs'] ?? null) && array_key_exists('mediaId', $node['attrs'])) {
            $old = $node['attrs']['mediaId'];
            $new = $this->reference($old, $path, $locale, false, $this->occurrencePath($path, $locale));
            $id = $this->idOf($new);

            if ($new !== $old) {
                $node['attrs']['mediaId'] = $new;
            }

            // Het adres volgt het bestand dat er nu bij hoort.
            if ($this->replace !== null && $id !== null && isset($this->urls[$id]) && ($new !== $old || $this->sourcesOnly)
                && is_string($node['attrs']['src'] ?? null) && $node['attrs']['src'] !== '' && $node['attrs']['src'] !== $this->urls[$id]) {
                $node['attrs']['src'] = $this->urls[$id];

                if ($this->sourcesOnly) {
                    $this->changed++;
                }
            }
        }

        if (is_array($node['content'] ?? null)) {
            foreach ($node['content'] as $index => $child) {
                if (is_array($child)) {
                    $node['content'][$index] = $this->readTipTap($child, $path, $locale, $depth + 1);
                }
            }
        }

        return $node;
    }

    /**
     * Opgeslagen HTML: het element met `data-media-id` (de wrapper, soms de
     * afbeelding zelf) en de afbeelding die er direct in staat.
     */
    private function readHtml(string $html, string $path, string $locale): string
    {
        $tag = '/<([a-z][a-z0-9]*)\b[^<>]*?\bdata-media-id\s*=\s*(["\']?)(\d+)\2[^<>]*>/i';

        if (! preg_match_all($tag, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return $html;
        }

        $edits = [];
        // De id's van een wrapper en van de afbeelding erin horen bij één plek.
        $last = null;

        foreach ($matches as $index => $match) {
            [$element, $offset] = $match[0];
            $old = $match[3][0];
            $isImage = strtolower($match[1][0]) === 'img';
            $sameSpot = $last !== null && $isImage && $last['id'] === $old && $offset - $last['end'] < 20;
            $precise = $sameSpot ? $last['precise'] : $this->occurrencePath($path, $locale);

            // Voor de tweede tag van dezelfde plek niet opnieuw tellen.
            $before = [count($this->rewritten), count($this->found)];
            $new = $this->reference($old, $path, $locale, false, $precise);

            if ($sameSpot && count($this->rewritten) > $before[0]) {
                array_pop($this->rewritten);
                $this->changed--;
            }

            $last = ['id' => $old, 'end' => $offset + strlen($element), 'precise' => $precise];
            $id = $this->idOf($new);
            $url = $this->replace !== null && $id !== null && ((string) $new !== $old || $this->sourcesOnly) ? ($this->urls[$id] ?? null) : null;
            $rewritten = $element;

            if ((string) $new !== $old) {
                $rewritten = preg_replace('/(\bdata-media-id\s*=\s*)(["\']?)\d+\2/i', '${1}${2}' . $new . '${2}', $rewritten, 1) ?? $rewritten;
            }

            if ($url !== null) {
                $rewritten = $this->withSource($rewritten, $url);

                // De afbeelding binnen een wrapper draagt zelf geen id.
                if (! $isImage) {
                    $next = $matches[$index + 1][0][1] ?? strlen($html);
                    $from = $offset + strlen($element);

                    if (preg_match('/<img\b[^<>]*>/i', $html, $image, PREG_OFFSET_CAPTURE, $from) && $image[0][1] < min($next, $from + 2000)) {
                        $edits[] = [$image[0][1], strlen($image[0][0]), $this->withSource($image[0][0], $url)];
                    }
                }
            }

            if ($rewritten !== $element) {
                $edits[] = [$offset, strlen($element), $rewritten];
            }
        }

        if ($edits === []) {
            return $html;
        }

        // Van achter naar voren, zodat de posities kloppen. Dezelfde plek maar één keer.
        usort($edits, fn (array $a, array $b) => $b[0] <=> $a[0]);
        $done = [];
        $changedHere = false;

        foreach ($edits as [$offset, $length, $replacement]) {
            if (isset($done[$offset]) || substr($html, $offset, $length) === $replacement) {
                continue;
            }

            $done[$offset] = true;
            $html = substr_replace($html, $replacement, $offset, $length);
            $changedHere = true;
        }

        if ($changedHere && $this->sourcesOnly) {
            $this->changed++;
        }

        return $html;
    }

    /** Zet `src` en `data-src` van één tag op het nieuwe adres. */
    private function withSource(string $tag, string $url): string
    {
        $safe = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace_callback(
            '/(\s(?:data-)?src\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            fn (array $match) => $match[1] . '"' . $safe . '"',
            $tag,
        ) ?? $tag;
    }

    private function occurrencePath(string $path, string $locale): string
    {
        $key = $path . '|' . $locale;
        $number = $this->occurrence[$key] = ($this->occurrence[$key] ?? 0) + 1;

        return $path . '#' . $number;
    }

    private function idOf(mixed $value): ?int
    {
        return match (true) {
            is_int($value) && $value > 0 => $value,
            is_string($value) && $value !== '' && strlen($value) <= 18 && ctype_digit($value) && (int) $value > 0 => (int) $value,
            default => null,
        };
    }

    /**
     * Noteert één waarde als verwijzing als hij alleen uit cijfers bestaat;
     * een pad, een url of een versleutelde sleutel telt niet. Geeft de
     * waarde terug, bij rewrite() de vervangende.
     *
     * @param  string|null  $precise  de plek binnen het attribuut (images.2), voor het logboek van een samenvoeging
     */
    private function reference(mixed $value, string $path, string $locale, bool $guess = false, ?string $precise = null): mixed
    {
        $id = $this->idOf($value);

        if ($id === null) {
            return $value;
        }

        if ($this->replace !== null) {
            // Een gok wordt nooit herschreven, en bij "alleen het adres" blijft elk id.
            if ($guess || $this->sourcesOnly || ! isset($this->replace[$id])) {
                return $value;
            }

            $precise = mb_substr($precise ?? $path, 0, self::MAX_FIELD_LENGTH);

            if ($this->only !== null && ! isset($this->only[$precise . '|' . $locale])) {
                return $value;
            }

            $this->changed++;
            $this->rewritten[] = ['from' => $id, 'to' => $this->replace[$id], 'field' => $precise, 'locale' => mb_substr($locale, 0, 10)];

            // Het type van de waarde blijft: een "12" wordt geen 12.
            return is_string($value) ? (string) $this->replace[$id] : $this->replace[$id];
        }

        if (count($this->found) >= self::MAX_REFERENCES) {
            return $value;
        }

        $field = mb_substr($path, 0, self::MAX_FIELD_LENGTH);
        $key = $id . '|' . $field . '|' . $locale;

        if (isset($this->found[$key])) {
            // Een zekere verwijzing wint van een gok op dezelfde plek.
            if ($this->found[$key]['guess'] && ! $guess) {
                $this->found[$key]['guess'] = false;
                $this->guesses--;
            }

            return $value;
        }

        if ($guess) {
            if ($this->guesses >= self::MAX_GUESSES) {
                if ($this->guesses === self::MAX_GUESSES) {
                    $this->guesses++;
                    Log::warning('Media usage: more than ' . self::MAX_GUESSES . ' guessed references in one model; the rest is left out.');
                }

                return $value;
            }

            $this->guesses++;
        }

        $this->found[$key] = ['media_item_id' => $id, 'field' => $field, 'locale' => mb_substr($locale, 0, 10), 'guess' => $guess];

        return $value;
    }

    private function isTranslatable(Model $model, string $attribute): bool
    {
        return method_exists($model, 'isTranslatableAttribute') && method_exists($model, 'getTranslations')
            && rescue(fn () => $model->isTranslatableAttribute($attribute), false, false);
    }

    private function looksPerLocale(array $value): bool
    {
        if ($value === [] || array_is_list($value)) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            // nl, en, nl_BE, pt-BR
            if (! is_string($key) || ! preg_match('/^[a-z]{2,3}([_-][A-Za-z]{2,4})?$/', $key)) {
                return false;
            }
        }

        return true;
    }

    private function decoded(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && ($value[0] === '[' || $value[0] === '{')) {
            $decoded = json_decode($value, true);

            // Rijke tekst als TipTap-JSON blijft ook als array leesbaar.
            return is_array($decoded) ? $decoded : $value;
        }

        return $value;
    }

    /**
     * De nieuwe waarde in de vorm waarin het attribuut hem bewaart: een
     * kolom zonder cast die JSON als tekst droeg krijgt weer tekst.
     */
    private function encodeLike(Model $model, string $attribute, mixed $value): mixed
    {
        if (is_array($value) && ! $model->hasCast($attribute) && is_string($model->getAttributes()[$attribute] ?? null)) {
            return json_encode($value);
        }

        return $value;
    }
}
