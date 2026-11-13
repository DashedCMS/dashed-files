<?php

namespace Dashed\DashedFiles\Classes\Legacy;

use ReflectionClass;
use Composer\InstalledVersions;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Models\MediaFolder;
use Dashed\DashedFiles\Filament\Forms\Components\LegacyAdvancedFileUpload;

/**
 * Laat klantcode die nog de modellen van het oude mediabibliotheekpakket
 * noemt gewoon werken als dat pakket weg is: de oude klassenaam wordt dan
 * een alias (class_alias) van MediaItem of MediaFolder. Niemand hoeft daar
 * per project iets voor aan te passen.
 *
 * Een autoloader achteraan in de keten. PHP vraagt hem pas om een klasse als
 * composer (en elke andere loader ervoor) hem niet vond. Zolang het oude
 * pakket geïnstalleerd is (release A) laadt composer dus de echte klasse en
 * doet dit niets.
 *
 * Aangemeld vanuit `autoload.files` van dashed-files (src/Helpers/
 * legacy-class-aliases.php), dus zodra vendor/autoload.php geladen is: een
 * geserialiseerde wachtrijtaak, een cache-waarde of een blade-component kan
 * de oude naam noemen voordat er één provider geboot is. register() van de
 * ServiceProvider meldt hem nog eens aan (zonder effect als hij er al staat),
 * voor een installatie waar de autoload-bestanden van composer nog niet
 * opnieuw geschreven zijn.
 *
 * De namen staan in stukken, op dezelfde manier als in
 * LegacyMediaCodeRewriter: MediaLibraryVendorGuardTest verbiedt de
 * pakketnaam als letterlijke tekst, en die lijst mag alleen krimpen. Deze
 * klasse gebruikt de namen alleen als tekst om op te reageren, nooit als
 * import, dus een uitzondering in de guard zou meer toestaan dan nodig is.
 */
final class LegacyClassAliases
{
    public const VENDOR = 'Ralph' . 'JSmit';

    public const PREFIX = self::VENDOR . '\\Filament\\MediaLibrary\\';

    public const ITEM = self::PREFIX . 'Models\\MediaLibraryItem';

    public const MEDIA_ITEM = self::PREFIX . 'Media\\Models\\MediaLibraryItem';

    public const FOLDER = self::PREFIX . 'Models\\MediaLibraryFolder';

    public const MEDIA_FOLDER = self::PREFIX . 'Media\\Models\\MediaLibraryFolder';

    /** Het uploadveld uit het tweede betaalde pakket (ralphjsmit/laravel-filament-upload). */
    public const UPLOAD_FIELD = self::VENDOR . '\\Filament\\Upload\\Filament\\Forms\\Components\\AdvancedFileUpload';

    /** De composer-naam van het oude pakket. */
    public const PACKAGE = 'ralphjsmit/laravel-filament-media-library';

    /** @var array<string, class-string> oude klassenaam => onze klasse */
    public const MAP = [
        self::ITEM => MediaItem::class,
        self::MEDIA_ITEM => MediaItem::class,
        self::FOLDER => MediaFolder::class,
        self::MEDIA_FOLDER => MediaFolder::class,
        self::UPLOAD_FIELD => LegacyAdvancedFileUpload::class,
    ];

    /**
     * De schakelaar `dashed-files.legacy_aliases`. De ServiceProvider zet hem
     * in register(); tot dan (vóór de boot) staat hij aan, want de
     * configuratie is er dan nog niet.
     */
    public static bool $enabled = true;

    /** @var array<string, class-string>|null testhaak: een andere lijst dan MAP */
    private static ?array $map = null;

    /** @var array<string, class-string> wat dit proces echt als alias heeft gemaakt */
    private static array $aliased = [];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        // Achteraan: composer en elke andere loader gaan voor.
        spl_autoload_register([self::class, 'load'], true, false);
    }

    /**
     * De autoloader. Reageert alleen op de vaste lijst en alleen als de
     * klasse nog niet bestaat.
     */
    public static function load(string $class): void
    {
        $target = self::targetFor($class);

        if ($target === null || ! self::$enabled) {
            return;
        }

        // Zonder autoload: bestaat hij al (de echte klasse of een eerdere
        // alias), dan blijft dat zo.
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return;
        }

        if (class_alias($target, $class)) {
            self::$aliased[strtolower(ltrim($class, '\\'))] = $target;
        }
    }

    /** Onze klasse voor een oude naam, of null. Klassenamen zijn hoofdletterongevoelig. */
    public static function targetFor(string $class): ?string
    {
        $needle = strtolower(ltrim($class, '\\'));

        foreach (self::map() as $old => $target) {
            if (strtolower($old) === $needle) {
                return $target;
            }
        }

        return null;
    }

    /** @return array<string, class-string> */
    public static function map(): array
    {
        return self::$map ?? self::MAP;
    }

    /**
     * Testhaak: in de testomgeving is het oude pakket geïnstalleerd, dus de
     * echte namen laadt composer altijd. Een test geeft hier verzonnen
     * namen op die nergens bestaan. Null zet de echte lijst terug.
     *
     * @param  array<string, class-string>|null  $map
     */
    public static function useMapForTesting(?array $map): void
    {
        self::$map = $map;
    }

    /**
     * De oude namen die in dit proces een alias van ons model zijn.
     *
     * @return array<string, class-string>
     */
    public static function aliased(): array
    {
        return self::$aliased;
    }

    /**
     * Of een oude naam de echte klasse van het pakket is, en niet onze alias.
     * Laadt hem zo nodig (en maakt dus de alias als het pakket weg is).
     */
    public static function isPackageClass(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        return strcasecmp((new ReflectionClass($class))->getName(), ltrim($class, '\\')) === 0;
    }

    /**
     * De echte pakketklasse, of null als het pakket weg is (dan is de naam
     * onze alias en hoeft niemand er apart naar te luisteren).
     */
    public static function packageClass(string $class): ?string
    {
        return self::isPackageClass($class) ? $class : null;
    }

    /** Of composer het oude pakket nog als geïnstalleerd kent. */
    public static function packageInstalled(): bool
    {
        try {
            return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE);
        } catch (\Throwable) {
            return false;
        }
    }
}
