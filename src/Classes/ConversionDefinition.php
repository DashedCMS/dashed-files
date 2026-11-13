<?php

namespace Dashed\DashedFiles\Classes;

/**
 * Eén afbeeldingsformaat, los van Spatie: naam, maten en hoe er gesneden
 * wordt. De kolom `conversions` bewaart wat een thema ooit vroeg
 * (`{"widen":300}`, `"huge"`); deze klasse vertaalt dat.
 */
final class ConversionDefinition
{
    public const MODE_WIDTH = 'width';

    public const MODE_HEIGHT = 'height';

    public const MODE_CROP = 'crop';

    public const MODE_CONTAIN = 'contain';

    public const FORMAT = 'webp';

    /** Breedte per vaste naam. */
    public const FIXED = [
        'huge' => 1600,
        'large' => 1200,
        'medium' => 800,
        'small' => 400,
        'tiny' => 200,
    ];

    private const MODES = [
        'widen' => self::MODE_WIDTH,
        'heighten' => self::MODE_HEIGHT,
        'fit' => self::MODE_CROP,
        'contain' => self::MODE_CONTAIN,
    ];

    public function __construct(
        public readonly string $name,
        public readonly string $mode,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly string $format = self::FORMAT,
        // Het focuspunt in procenten, alleen bij een formaat dat bijsnijdt en
        // alleen als het niet het midden is.
        public readonly ?array $focal = null,
    ) {
    }

    public const AVIF = 'avif';

    public const AVIF_SUFFIX = '-avif';

    public const ADMIN_THUMB = 'admin-thumb';

    /** De miniatuur van de bibliotheek: 600 bij 600, bijgesneden. */
    public static function thumb(): self
    {
        return new self('thumb', self::MODE_CROP, 600, 600);
    }

    /**
     * De miniatuur voor het beheer: 320 bij 320, bijgesneden met het
     * focuspunt. De tegels, de lijst, het mediaveld en het keuzevenster
     * tonen hem; `thumb` blijft er voor de thema's en voor een scherm met
     * dubbele dichtheid.
     */
    public static function adminThumb(): self
    {
        return new self(self::ADMIN_THUMB, self::MODE_CROP, 320, 320);
    }

    public function isCropping(): bool
    {
        return $this->mode === self::MODE_CROP;
    }

    /**
     * Het achtervoegsel van een focuspunt in een formaatnaam: `-f3070` voor
     * 30 procent van links en 70 van boven. Leeg zonder focuspunt en bij het
     * midden: dan blijven naam, bestand en adres zoals ze altijd waren.
     */
    public static function focalSuffix(?int $x, ?int $y): string
    {
        if ($x === null || $y === null || ($x === 50 && $y === 50)) {
            return '';
        }

        $x = max(0, min(100, $x));
        $y = max(0, min(100, $y));

        return sprintf('-f%02d%02d', $x, $y);
    }

    /** Of een formaatnaam op een focus-achtervoegsel eindigt, en zo ja de naam zonder. */
    public static function withoutFocalSuffix(string $name): string
    {
        return (string) preg_replace('/-f\d{4,6}$/', '', $name);
    }

    /**
     * Dit formaat voor een item met dit focuspunt. Alleen een formaat dat
     * bijsnijdt krijgt een andere naam; al het andere blijft zichzelf.
     */
    public function withFocal(?int $x, ?int $y): self
    {
        $suffix = $this->isCropping() ? self::focalSuffix($x, $y) : '';

        if ($suffix === '') {
            return $this;
        }

        return new self($this->name . $suffix, $this->mode, $this->width, $this->height, $this->format, ['x' => max(0, min(100, (int) $x)), 'y' => max(0, min(100, (int) $y))]);
    }

    /** De AVIF-variant: een eigen formaat naast het webp-formaat. */
    public function asAvif(): self
    {
        return new self($this->name . self::AVIF_SUFFIX, $this->mode, $this->width, $this->height, self::AVIF, $this->focal);
    }

    /**
     * De naam waaronder een gevraagd formaat voor dit focuspunt op de schijf
     * staat. De ene plek voor het leespad: `fit-400-400` wordt
     * `fit-400-400-f3070`, `thumb` wordt `thumb-f3070`, al het andere blijft.
     */
    public static function effectiveName(string|array $conversion, ?int $x, ?int $y): string
    {
        $name = self::nameOf($conversion) ?: 'original';

        if (self::focalSuffix($x, $y) === '') {
            return $name;
        }

        $definition = match ($name) {
            'thumb' => self::thumb(),
            self::ADMIN_THUMB => self::adminThumb(),
            default => self::fromLegacy($conversion),
        };

        return $definition ? $definition->withFocal($x, $y)->name : $name;
    }

    public static function medium(): self
    {
        return new self('medium', self::MODE_WIDTH, self::FIXED['medium']);
    }

    /**
     * Van een opgeslagen sleutel naar een definitie: de arrayvorm uit de
     * kolom (`['widen' => 300]`), de naam die daarvan is afgeleid
     * (`widen-300`, `fit-80-80`) of een vaste naam. Null voor alles wat
     * geen eigen formaat is: `original`, de formaten van de bibliotheek zelf
     * (`thumb`, `responsive`) en onbekende of onvolledige sleutels.
     */
    public static function fromLegacy(string|array $key): ?self
    {
        if (is_string($key)) {
            if (isset(self::FIXED[$key])) {
                return new self($key, self::MODE_WIDTH, self::FIXED[$key]);
            }

            $parts = explode('-', $key);
            $operation = array_shift($parts);

            if (! isset(self::MODES[$operation]) || $parts === [] || array_filter($parts, fn (string $part) => ! ctype_digit($part)) !== []) {
                return null;
            }

            return self::build($key, $operation, array_map('intval', $parts));
        }

        $definition = null;

        // Bij meer dan één bewerking in dezelfde array wint de laatste, zoals
        // het altijd was: ze deelden één naam en de laatste registratie
        // bepaalde het bestand. De naam komt van de hele array, want onder
        // die naam vraagt het thema het op.
        foreach ($key as $operation => $value) {
            if (is_string($operation) && isset(self::MODES[$operation])) {
                $definition = self::build(self::nameOf($key), $operation, array_values((array) $value)) ?? $definition;
            }
        }

        return $definition;
    }

    /**
     * De naam waaronder een gevraagd formaat wordt opgeslagen en gecachet.
     * De arrayvorm levert `widen-300` of `fit-80-80`; een lege array levert
     * een lege string, daar maakt de aanroeper `original` van.
     */
    public static function nameOf(string|array $conversion, bool $isChild = false): string
    {
        if (! is_array($conversion)) {
            return $conversion ?: 'original';
        }

        $name = '';

        foreach ($conversion as $key => $value) {
            if (! is_int($key)) {
                $name .= "{$key}-";
            }

            if ($isChild) {
                $name .= '-';
            }

            $name .= is_array($value) ? self::nameOf($value, true) : (string) $value;
        }

        return str_replace('--', '-', $name);
    }

    private static function build(string $name, string $operation, array $values): ?self
    {
        $first = isset($values[0]) && is_numeric($values[0]) ? (int) $values[0] : null;
        $second = isset($values[1]) && is_numeric($values[1]) ? (int) $values[1] : null;

        if (! $first) {
            return null;
        }

        return match (self::MODES[$operation]) {
            self::MODE_WIDTH => new self($name, self::MODE_WIDTH, $first),
            self::MODE_HEIGHT => new self($name, self::MODE_HEIGHT, null, $first),
            default => $second ? new self($name, self::MODES[$operation], $first, $second) : null,
        };
    }
}
