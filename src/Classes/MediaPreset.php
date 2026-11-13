<?php

namespace Dashed\DashedFiles\Classes;

use InvalidArgumentException;

/**
 * Een benoemd formaat. Een alias: het ontvouwt naar de arrayvorm die thema's
 * altijd al gebruikten (`['fit' => [400, 400]]`), dus de formaatnaam, het
 * bestand en het adres zijn dezelfde en bestaande bestanden worden hergebruikt.
 */
final class MediaPreset
{
    /** @var array<int, int> */
    private array $widths = [];

    private ?string $sizes = null;

    /** @var array<int, string> */
    private array $formats = ['webp', 'avif'];

    private function __construct(
        public readonly string $operation,
        public readonly ?int $width,
        public readonly ?int $height,
    ) {
    }

    public static function widen(int $width): self
    {
        return new self('widen', self::positive($width), null);
    }

    public static function fit(int $width, int $height): self
    {
        return new self('fit', self::positive($width), self::positive($height));
    }

    public static function heighten(int $height): self
    {
        return new self('heighten', null, self::positive($height));
    }

    public static function contain(int $width, int $height): self
    {
        return new self('contain', self::positive($width), self::positive($height));
    }

    /**
     * De ladder voor srcset: de breedtes waarin dit formaat wordt aangeboden.
     *
     * @param  array<int, int>  $widths
     */
    public function widths(array $widths): self
    {
        $clean = array_values(array_unique(array_filter(array_map(fn ($width) => is_numeric($width) ? (int) $width : 0, $widths), fn (int $width) => $width > 0 && $width <= 10000)));
        sort($clean);

        $this->widths = array_slice($clean, 0, 12);

        return $this;
    }

    /** De `sizes`-tekst, bijvoorbeeld `(min-width: 1024px) 25vw, 50vw`. */
    public function sizes(?string $sizes): self
    {
        $this->sizes = filled($sizes) ? trim((string) $sizes) : null;

        return $this;
    }

    /**
     * Standaard webp met AVIF erbij waar die bestaat en de instelling aan
     * staat; `formats(['webp'])` houdt een formaat bij webp alleen.
     *
     * @param  array<int, string>  $formats  `webp` en eventueel `avif`
     */
    public function formats(array $formats): self
    {
        $clean = array_values(array_intersect(['webp', 'avif'], array_map(fn ($format) => strtolower((string) $format), $formats)));

        $this->formats = in_array('webp', $clean, true) ? $clean : array_merge(['webp'], $clean);

        return $this;
    }

    /** De arrayvorm waar dit formaat een alias van is. */
    public function toConversion(): array
    {
        return $this->conversionFor($this->width);
    }

    /**
     * Hetzelfde formaat op een andere breedte. Een formaat dat bijsnijdt
     * schaalt zijn hoogte mee, zodat de verhouding gelijk blijft.
     */
    public function conversionFor(?int $width): array
    {
        return match ($this->operation) {
            'widen' => ['widen' => $width ?? $this->width],
            'heighten' => ['heighten' => $this->height],
            default => [$this->operation => [
                $width ?? $this->width,
                $width === null || $width === $this->width ? $this->height : max(1, (int) round($this->height * $width / $this->width)),
            ]],
        };
    }

    /**
     * De breedtes voor srcset, oplopend. Alleen `widen` en `fit` kennen een
     * ladder: bij `heighten` en `contain` hangt de echte breedte van de
     * verhouding van het bestand af.
     *
     * @param  array<int, int>|null  $override  de prop `widths` van het component
     * @return array<int, int>
     */
    public function ladder(?int $originalWidth = null, ?array $override = null): array
    {
        if (! in_array($this->operation, ['widen', 'fit'], true)) {
            return [];
        }

        $widths = $override !== null ? (clone $this)->widths($override)->widths : $this->widths;

        if ($widths === []) {
            return [];
        }

        // De basisbreedte hoort er altijd bij: dat is ook de `src`.
        $widths = array_values(array_unique(array_merge($widths, [$this->width])));
        sort($widths);

        // Niet breder dan het origineel: opschalen levert alleen een groter bestand op.
        return $originalWidth ? array_values(array_filter($widths, fn (int $width) => $width <= $originalWidth)) : $widths;
    }

    public function hasLadder(): bool
    {
        return $this->widths !== [];
    }

    public function sizesAttribute(): ?string
    {
        return $this->sizes;
    }

    public function wantsAvif(): bool
    {
        return in_array('avif', $this->formats, true);
    }

    /**
     * Van de arrayvorm terug naar een preset, voor de props `widths` en
     * `sizes` op een component dat geen benoemd formaat gebruikt.
     */
    public static function fromConversion(string|array $conversion): ?self
    {
        $definition = ConversionDefinition::fromLegacy($conversion);

        return match ($definition?->mode) {
            ConversionDefinition::MODE_WIDTH => self::widen($definition->width),
            ConversionDefinition::MODE_HEIGHT => self::heighten($definition->height),
            ConversionDefinition::MODE_CROP => self::fit($definition->width, $definition->height),
            ConversionDefinition::MODE_CONTAIN => self::contain($definition->width, $definition->height),
            default => null,
        };
    }

    private static function positive(int $value): int
    {
        if ($value < 1 || $value > 10000) {
            throw new InvalidArgumentException("A media preset needs a size between 1 and 10000, got [{$value}].");
        }

        return $value;
    }
}
