<?php

namespace Dashed\DashedFiles\Classes;

use Closure;
use ReflectionMethod;
use ReflectionNamedType;
use Illuminate\Support\Collection;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Enums\FileType;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;

/**
 * Gedeelde opslag voor gevonden bestanden van de MediaPicker, per verzoek.
 *
 * Elk MediaPicker-veld krijgt een eigen driver, en Filament bouwt het schema
 * bij elke ronde (vullen, valideren, dehydrateren, renderen) opnieuw op. De
 * cache die de driver zelf heeft leeft daardoor maar één veld en één ronde
 * lang, en een grote pagina vroeg dezelfde id's tientallen keren op. Deze
 * opslag is statisch, zodat alle drivers van één verzoek hem delen.
 *
 * Deze klasse staat bewust los van CachingMediaLibraryItemDriver: die erft van
 * de driver uit het pakket en geeft een fatale fout bij het inladen als de
 * handtekeningen daar ooit veranderen. Legen en de controle op
 * compatibiliteit moeten altijd veilig aan te roepen zijn.
 */
class MediaPickerFileCache
{
    /**
     * @var array<string, FileData>
     */
    protected static array $files = [];

    /**
     * De kolom `conversions` per media library item, ook als die leeg is of
     * het item niet gevonden werd: dat is net zo goed een antwoord.
     *
     * @var array<string, mixed>
     */
    protected static array $conversions = [];

    protected static ?bool $compatible = null;

    public static function get(string $key): ?FileData
    {
        return static::$files[$key] ?? null;
    }

    public static function put(string $key, FileData $file): void
    {
        static::$files[$key] = $file;
    }

    public static function rememberConversions(int|string $mediaLibraryItemId, Closure $callback): mixed
    {
        $key = (string) $mediaLibraryItemId;

        if (! array_key_exists($key, static::$conversions)) {
            static::$conversions[$key] = $callback();
        }

        return static::$conversions[$key];
    }

    public static function flush(): void
    {
        static::$files = [];
        static::$conversions = [];
    }

    /**
     * Klopt de driver uit het pakket nog met wat CachingMediaLibraryItemDriver
     * overschrijft? Zo niet, dan blijft het veld op de standaarddriver staan
     * en gedraagt alles zich zoals zonder deze cache.
     */
    public static function isCompatible(): bool
    {
        return static::$compatible ??= static::checkCompatibility();
    }

    protected static function checkCompatibility(): bool
    {
        if (! class_exists(MediaLibraryItemDriver::class) || ! class_exists(FileData::class) || ! enum_exists(FileType::class)) {
            return false;
        }

        if (! method_exists(FileData::class, 'getKey')) {
            return false;
        }

        $expected = [
            'findFile' => [['?' . FileType::class, 'string', 'array'], '?' . FileData::class],
            'findFiles' => [['?' . FileType::class, 'array', 'array'], Collection::class],
        ];

        foreach ($expected as $method => [$parameterTypes, $returnType]) {
            if (! method_exists(MediaLibraryItemDriver::class, $method)) {
                return false;
            }

            $reflection = new ReflectionMethod(MediaLibraryItemDriver::class, $method);

            if ($reflection->isFinal() || ! $reflection->isPublic()) {
                return false;
            }

            $parameters = $reflection->getParameters();

            if (count($parameters) !== count($parameterTypes) || ! $parameters[2]->isDefaultValueAvailable()) {
                return false;
            }

            foreach ($parameters as $index => $parameter) {
                if (static::typeName($parameter->getType()) !== $parameterTypes[$index]) {
                    return false;
                }
            }

            if (static::typeName($reflection->getReturnType()) !== $returnType) {
                return false;
            }
        }

        return true;
    }

    protected static function typeName(mixed $type): ?string
    {
        if (! $type instanceof ReflectionNamedType) {
            return null;
        }

        return ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '') . $type->getName();
    }
}
