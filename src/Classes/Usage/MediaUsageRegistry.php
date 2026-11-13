<?php

namespace Dashed\DashedFiles\Classes\Usage;

use Closure;
use InvalidArgumentException;

/**
 * Welke kolommen en instellingen een media-id kunnen bevatten. De
 * gebruiksindex ("waar gebruikt") leest alleen wat hier is aangemeld; een
 * nieuw model met een mediaveld hoort hier bij, en de guard-test
 * MediaUsageRegistryGuardTest wordt rood tot dat gebeurd is.
 */
final class MediaUsageRegistry
{
    public const KINDS = ['id', 'ids', 'blocks', 'richtext', 'custom_blocks'];

    public const SETTING_MODEL = 'Dashed\\DashedCore\\Models\\Customsetting';

    /** @var array<class-string, array<string, array{kind: string, when: ?Closure}>> */
    private static array $attributes = [];

    /** @var array<string, true> */
    private static array $settings = [];

    /** @var array<int, Closure> */
    private static array $extensions = [];

    private static bool $extended = false;

    /** @var array<class-string, class-string|false> de aangemelde klasse per modelklasse, ook voor een kindklasse */
    private static array $resolved = [];

    /**
     * @param  string  $kind  id (één media-id), ids (een lijst), blocks (een Builder-kolom), richtext (TipTap of HTML), custom_blocks (de blocks-kolom van CustomBlock)
     * @param  Closure|null  $when  krijgt het model; alleen bij true telt de kolom mee
     */
    public static function attribute(string $modelClass, string $attribute, string $kind = 'id', ?Closure $when = null): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown media usage kind [{$kind}].");
        }

        self::$attributes[ltrim($modelClass, '\\')][$attribute] = ['kind' => $kind, 'when' => $when];
        self::$resolved = [];
    }

    /** Een Customsetting-sleutel die een media-id bevat. */
    public static function setting(string $name): void
    {
        self::$settings[$name] = true;
        self::$resolved = [];
    }

    /**
     * Voor een klantproject: de callback meldt eigen velden aan en draait
     * één keer, vlak voordat het register voor het eerst gelezen wordt.
     */
    public static function extend(Closure $callback): void
    {
        self::$extensions[] = $callback;
        self::$extended = false;
    }

    /**
     * @return array<string, string> attribuut => soort
     */
    public static function for(string $modelClass): array
    {
        self::runExtensions();

        return array_map(fn (array $entry) => $entry['kind'], self::$attributes[ltrim($modelClass, '\\')] ?? []);
    }

    public static function conditionFor(string $modelClass, string $attribute): ?Closure
    {
        self::runExtensions();

        return self::$attributes[ltrim($modelClass, '\\')][$attribute]['when'] ?? null;
    }

    /**
     * @return array<int, class-string> zonder het instellingenmodel
     */
    public static function models(): array
    {
        self::runExtensions();

        return array_keys(self::$attributes);
    }

    /**
     * @return array<int, string>
     */
    public static function settings(): array
    {
        self::runExtensions();

        return array_keys(self::$settings);
    }

    public static function isSetting(mixed $name): bool
    {
        self::runExtensions();

        return is_string($name) && isset(self::$settings[$name]);
    }

    /**
     * De aangemelde klasse voor een modelklasse, of null. Een klantproject
     * erft soms van een pakketmodel; die kindklasse telt als zijn ouder.
     * Het antwoord wordt per klasse onthouden: de luisteraar die dit vraagt
     * vuurt voor elk model dat in het CMS wordt opgeslagen.
     */
    public static function resolve(string $modelClass): ?string
    {
        if (isset(self::$resolved[$modelClass])) {
            return self::$resolved[$modelClass] ?: null;
        }

        self::runExtensions();

        $found = false;

        if (isset(self::$attributes[$modelClass]) || ($modelClass === self::SETTING_MODEL && self::$settings !== [])) {
            $found = $modelClass;
        } else {
            foreach ([...array_keys(self::$attributes), ...(self::$settings === [] ? [] : [self::SETTING_MODEL])] as $registered) {
                if (is_subclass_of($modelClass, $registered)) {
                    $found = $registered;

                    break;
                }
            }
        }

        self::$resolved[$modelClass] = $found;

        return $found ?: null;
    }

    public static function flush(): void
    {
        self::$attributes = [];
        self::$settings = [];
        self::$extensions = [];
        self::$extended = false;
        self::$resolved = [];
    }

    private static function runExtensions(): void
    {
        if (self::$extended) {
            return;
        }

        // Eerst de vlag: een callback die zelf het register leest mag geen kring maken.
        self::$extended = true;
        $extensions = self::$extensions;
        self::$extensions = [];

        foreach ($extensions as $extension) {
            rescue(fn () => $extension());
        }

        self::$resolved = [];
    }
}
