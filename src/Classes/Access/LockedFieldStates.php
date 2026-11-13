<?php

namespace Dashed\DashedFiles\Classes\Access;

use function Livewire\on;

use Illuminate\Support\Arr;

use function Livewire\store;

/**
 * De waarde waarmee een alleen-lezen mediaveld geladen is, per veld (op zijn
 * statepath) bewaard bij het Livewire-component.
 *
 * Een gebruiker zonder `view_media` mag een mediaveld zien maar niet
 * wijzigen. De staat van het veld reist via de browser en is dus niet te
 * vertrouwen; de waarde van het laden reist daarom mee in de memo van de
 * Livewire-snapshot. Die memo valt onder de handtekening van de snapshot
 * (HMAC met de app-sleutel) en is, anders dan een publieke eigenschap, niet
 * via een update uit de browser te wijzigen. Geen sessie en dus geen
 * plafond: een formulier met honderden velden, of tien tabbladen naast
 * elkaar, verliest niets.
 *
 * De statepath van een veld in een Builder of Repeater bevat de sleutel van
 * het item (een uuid), niet zijn plek: herordenen verschuift hem niet.
 *
 * Ontbreekt de waarde voor een veld toch (het recht verviel midden in de
 * sessie, een blok is op de server gekloond), dan geldt wat de server zelf
 * in de snapshot had staan: de waarde van vóór de updates van dit verzoek.
 * Een opslag wordt daar nooit door geblokkeerd.
 */
final class LockedFieldStates
{
    public const MEMO = 'dashedMediaLocked';

    private const BEFORE = 'dashedMediaBeforeUpdate';

    /** Vanuit de boot van het pakket: de drie momenten van Livewire waar de waarden meereizen. */
    public static function register(): void
    {
        on('hydrate', function ($component, $memo) {
            $known = is_array($memo[self::MEMO] ?? null) ? $memo[self::MEMO] : [];

            if ($known !== []) {
                store($component)->set(self::MEMO, $known);
            }
        });

        // Vlak voor een update uit de browser wordt toegepast: wat er stond.
        on('update', function ($component, $fullPath, $newValue) {
            $before = (array) store($component)->get(self::BEFORE, []);

            if (! array_key_exists($fullPath, $before)) {
                $before[$fullPath] = ['value' => data_get($component, $fullPath)];
                store($component)->set(self::BEFORE, $before);
            }
        });

        on('dehydrate', function ($component, $context) {
            $known = (array) store($component)->get(self::MEMO, []);

            if ($known === []) {
                return;
            }

            // Velden die niet meer bestaan (een verwijderd blok, een opnieuw gevuld formulier) reizen niet mee.
            $state = rescue(fn () => $component->all(), [], false);
            $known = array_filter($known, fn (string $path) => Arr::has($state, $path), ARRAY_FILTER_USE_KEY);

            if ($known !== []) {
                $context->addMemo(self::MEMO, $known);
            }
        });
    }

    public static function remember(object $component, string $path, mixed $value): void
    {
        $known = (array) store($component)->get(self::MEMO, []);
        $known[$path] = ['value' => $value];

        store($component)->set(self::MEMO, $known);
    }

    /**
     * @return array{value: mixed}|null null als er voor dit veld niets bewaard is
     */
    public static function recall(object $component, string $path): ?array
    {
        $known = ((array) store($component)->get(self::MEMO, []))[$path] ?? null;

        return is_array($known) && array_key_exists('value', $known) ? ['value' => $known['value']] : null;
    }

    /**
     * Wat er op dit pad stond voordat de browser het in dit verzoek
     * wijzigde, rechtstreeks of via een pad erboven.
     *
     * @return array{value: mixed}|null null als geen update dit pad raakte
     */
    public static function beforeUpdates(object $component, string $path): ?array
    {
        foreach ((array) store($component)->get(self::BEFORE, []) as $updated => $before) {
            if ($updated === $path) {
                return ['value' => $before['value']];
            }

            if (str_starts_with($path, $updated . '.')) {
                return ['value' => data_get($before['value'], substr($path, strlen($updated) + 1))];
            }

            // Een update dieper dan het veld (één id in een lijst): de hele waarde is dan niet meer te vertrouwen.
            if (str_starts_with($updated, $path . '.')) {
                return null;
            }
        }

        return null;
    }
}
