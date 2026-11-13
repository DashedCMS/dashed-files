<?php

namespace Dashed\DashedFiles\Classes\Preview;

/**
 * Begrenst wat uit een bestand of uit de uitvoer van een programma komt
 * voordat het de database in gaat.
 */
final class PreviewNumbers
{
    public const MAX_DIMENSION = 16384;

    // Tien dagen: langer is geen video maar een kapotte kop.
    public const MAX_DURATION = 864000.0;

    public const MAX_PAGES = 100000;

    public static function dimension(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 && (int) $value <= self::MAX_DIMENSION ? (int) $value : null;
    }

    public static function duration(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        // Afgerond op duizendsten; wat dan nul is, is geen duur.
        $value = is_finite($value) ? round($value, 3) : 0.0;

        return $value > 0 && $value <= self::MAX_DURATION ? $value : null;
    }

    public static function pages(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 && (int) $value <= self::MAX_PAGES ? (int) $value : null;
    }
}
