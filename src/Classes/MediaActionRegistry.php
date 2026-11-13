<?php

namespace Dashed\DashedFiles\Classes;

use Closure;
use Throwable;
use InvalidArgumentException;

/**
 * Acties die een pakket aan de mediabibliotheek hangt, los van Filaments
 * actieklassen. Een actie is een array:
 *
 *   key      unieke naam binnen de plek (a-z, 0-9, streepje, underscore, punt)
 *   label    tekst op de knop
 *   icon     heroicon-naam
 *   visible  optioneel, closure(MediaItem): bool (alleen op ITEM getoetst per item)
 *   confirm  optioneel, bevestigingstekst; of een closure op de selectie
 *            (closure(MediaItem|Collection): ?string) voor een tekst met het
 *            aantal erin. De browser vraagt die tekst dan op vóór het uitvoeren.
 *   fields   optioneel, lijst velden: ['name', 'label', 'type' => text|textarea|select, 'options' => [waarde => label], 'required' => bool]
 *   handle   closure(MediaItem|Collection $subject, array $data): ?string
 *            ITEM krijgt het item, BULK en TOOLBAR een collectie (TOOLBAR: leeg);
 *            de teruggegeven tekst wordt als melding getoond.
 *
 * De closure van register() wordt pas bij for() aangeroepen, zodat __() in
 * de labels niet tijdens de boot draait.
 */
final class MediaActionRegistry
{
    public const ITEM = 'item';

    public const BULK = 'bulk';

    public const TOOLBAR = 'toolbar';

    public const SLOTS = [self::ITEM, self::BULK, self::TOOLBAR];

    public const FIELD_TYPES = ['text', 'textarea', 'select'];

    /** @var array<string, array<int|string, Closure>> */
    private static array $providers = [];

    public static function register(string $slot, Closure $actions, ?string $name = null): void
    {
        if (! in_array($slot, self::SLOTS, true)) {
            throw new InvalidArgumentException("Unknown media action slot [{$slot}].");
        }

        // Met een naam vervangt een tweede registratie de eerste: een
        // ServiceProvider die twee keer boot levert zo geen dubbele knoppen.
        if ($name !== null) {
            self::$providers[$slot][$name] = $actions;

            return;
        }

        self::$providers[$slot][] = $actions;
    }

    /**
     * @return array<string, array{key: string, label: string, icon: ?string, visible: ?Closure, confirm: ?string, fields: array<int, array{name: string, label: string, type: string, options: array<int, array{value: string, label: string}>, required: bool}>, handle: Closure}> op sleutel
     */
    public static function for(string $slot): array
    {
        $actions = [];

        foreach (self::$providers[$slot] ?? [] as $provider) {
            try {
                $list = $provider();
            } catch (Throwable $exception) {
                // Een kapotte registratie van een ander pakket mag de pagina niet meenemen.
                report($exception);

                continue;
            }

            foreach (is_iterable($list) ? $list : [] as $action) {
                if ($clean = self::normalize($action)) {
                    $actions[$clean['key']] = $clean;
                }
            }
        }

        return $actions;
    }

    public static function find(string $slot, string $key): ?array
    {
        return in_array($slot, self::SLOTS, true) ? (self::for($slot)[$key] ?? null) : null;
    }

    /**
     * Wat de browser van een actie mag weten: geen closures.
     *
     * @return array<int, array{key: string, label: string, confirm: ?string, fields: array}>
     */
    public static function describe(string $slot): array
    {
        return array_values(array_map(
            fn (array $action) => [
                'key' => $action['key'],
                'label' => $action['label'],
                'confirm' => $action['confirm'] instanceof Closure ? null : $action['confirm'],
                'fields' => $action['fields'],
                // Alleen aanwezig als de tekst van de selectie afhangt.
                ...($action['confirm'] instanceof Closure ? ['confirmAsk' => true] : []),
            ],
            self::for($slot),
        ));
    }

    public static function flush(): void
    {
        self::$providers = [];
    }

    private static function normalize(mixed $action): ?array
    {
        if (is_object($action) && method_exists($action, 'toArray')) {
            $action = $action->toArray();
        }

        if (! is_array($action)) {
            return null;
        }

        $key = $action['key'] ?? null;

        if (! is_string($key) || ! preg_match('/^[a-z0-9][a-z0-9_.-]{0,59}$/i', $key) || ! (($action['handle'] ?? null) instanceof Closure)) {
            return null;
        }

        $fields = [];

        foreach (is_array($action['fields'] ?? null) ? $action['fields'] : [] as $field) {
            $name = is_array($field) ? ($field['name'] ?? null) : null;

            if (! is_string($name) || ! preg_match('/^[a-z0-9_]{1,40}$/i', $name)) {
                continue;
            }

            $type = in_array($field['type'] ?? 'text', self::FIELD_TYPES, true) ? ($field['type'] ?? 'text') : 'text';
            $options = [];

            foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $value => $label) {
                $options[] = ['value' => (string) $value, 'label' => (string) $label];
            }

            $fields[] = [
                'name' => $name,
                'label' => (string) ($field['label'] ?? $name),
                'type' => $type,
                'options' => $options,
                'required' => (bool) ($field['required'] ?? false),
            ];
        }

        return [
            'key' => $key,
            'label' => (string) ($action['label'] ?? $key),
            'icon' => is_string($action['icon'] ?? null) ? $action['icon'] : null,
            'visible' => ($action['visible'] ?? null) instanceof Closure ? $action['visible'] : null,
            'confirm' => ($action['confirm'] ?? null) instanceof Closure
                ? $action['confirm']
                : (filled($action['confirm'] ?? null) ? (string) $action['confirm'] : null),
            'fields' => $fields,
            'handle' => $action['handle'],
        ];
    }
}
