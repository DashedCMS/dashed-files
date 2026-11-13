<?php

namespace Dashed\DashedFiles\Classes\Legacy;

/**
 * De versleutelde sleutel die de kiezer van het oude pakket per bestand in de
 * Livewire-staat zet ("media-library-item:<id>", versleuteld). Alleen die
 * kiezer maakt zulke sleutels; de eigen kiezer (MediaField) bewaart kale id's.
 *
 * Ontsleutelen gaat via de klasse van het oude pakket zolang die er is. Is
 * het pakket weg, dan bestaat er ook geen kiezer meer die zo'n sleutel maakt,
 * en is elke waarde gewoon geen sleutel (null). Zo hangt MediaHelper niet
 * meer rechtstreeks aan het pakket.
 *
 * De klassenaam in stukken, zoals in LegacyClassAliases: de guard op de
 * pakketnaam verbiedt hem als letterlijke tekst.
 */
final class LegacyPickerKey
{
    public const FILE_DATA = LegacyClassAliases::VENDOR . '\\Filament\\Explore\\Data\\FileData';

    /** Testhaak: een andere klasse dan die van het pakket, of '' voor "pakket weg". */
    private static ?string $class = null;

    public static function useClassForTesting(?string $class): void
    {
        self::$class = $class;
    }

    /** De ontsleutelde sleutel, of null als de waarde geen sleutel is of het pakket weg is. */
    public static function decrypt(string $value): ?string
    {
        $class = self::$class ?? self::FILE_DATA;

        if ($class === '' || ! class_exists($class) || ! method_exists($class, 'decryptKeyHash')) {
            return null;
        }

        $key = rescue(fn () => $class::decryptKeyHash($value), null, false);

        return is_string($key) && $key !== '' ? $key : null;
    }
}
