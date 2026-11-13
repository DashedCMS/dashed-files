<?php

namespace Dashed\DashedFiles\Classes;

use InvalidArgumentException;

/**
 * Benoemde formaten. Een pakket of project meldt ze aan in de boot-fase:
 *
 *     MediaPresets::register('product-card', MediaPreset::fit(400, 400)
 *         ->widths([200, 400, 800])
 *         ->sizes('(min-width: 1024px) 25vw, 50vw'));
 *
 * Daarna werkt `mediaHelper()->getSingleMedia($id, 'product-card')` precies
 * als `['fit' => [400, 400]]`, en geeft
 * `<x-dashed-files::image :mediaId="$id" preset="product-card" />` een
 * srcset met de breedtes die al gegenereerd zijn. In de kolom `conversions`
 * komt nooit de naam van een preset, altijd de arrayvorm.
 */
final class MediaPresets
{
    /** Namen die al een formaat zijn, of dat lijken. */
    public const RESERVED = ['original', 'huge', 'large', 'medium', 'small', 'tiny', 'thumb', 'admin-thumb', 'responsive'];

    /** @var array<string, MediaPreset> */
    private static array $presets = [];

    public static function register(string $name, MediaPreset $preset): void
    {
        if (! preg_match('/^[a-z][a-z0-9_-]{1,59}$/', $name)) {
            throw new InvalidArgumentException("A media preset name is lowercase letters, digits, dashes and underscores, got [{$name}].");
        }

        if (in_array($name, self::RESERVED, true)
            || preg_match('/^(widen|heighten|fit|contain)(-|$)/', $name)
            || ConversionDefinition::fromLegacy($name) !== null
            || str_ends_with($name, ConversionDefinition::AVIF_SUFFIX)
            || ConversionDefinition::withoutFocalSuffix($name) !== $name) {
            throw new InvalidArgumentException("The media preset name [{$name}] is reserved: it is, or looks like, an image format.");
        }

        // Een srcset met w-descriptors zonder `sizes` laat de browser van de
        // volle schermbreedte uitgaan: op een productkaart haalt hij dan het
        // grootste bestand. Dat mag de deur niet uit.
        if ($preset->hasLadder() && blank($preset->sizesAttribute())) {
            throw new InvalidArgumentException("The media preset [{$name}] has widths for a srcset but no sizes(): add ->sizes('...') or drop ->widths().");
        }

        self::$presets[$name] = $preset;
    }

    public static function get(string $name): ?MediaPreset
    {
        return self::$presets[$name] ?? null;
    }

    /**
     * @return array<string, MediaPreset>
     */
    public static function all(): array
    {
        return self::$presets;
    }

    public static function flush(): void
    {
        self::$presets = [];
    }

    /**
     * Wat een aanroeper vroeg, met een presetnaam ontvouwen naar de
     * arrayvorm. Al het andere, ook een onbekende tekst, gaat ongewijzigd terug.
     */
    public static function expand(string|array $conversion): string|array
    {
        return is_string($conversion) && isset(self::$presets[$conversion]) ? self::$presets[$conversion]->toConversion() : $conversion;
    }
}
