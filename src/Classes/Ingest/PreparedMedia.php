<?php

namespace Dashed\DashedFiles\Classes\Ingest;

/**
 * Een bestand dat door alle controles van de inname is en klaarstaat om
 * bewaard te worden. Gedeeld door een nieuwe upload en het vervangen van
 * een bestaand bestand, zodat beide precies dezelfde toetsen krijgen.
 */
final class PreparedMedia
{
    /**
     * @param  array<string, mixed>  $properties  custom properties voor de mediarij
     * @param  array{width: int, height: int}|null  $dimensions
     * @param  string|null  $ownCopy  een eigen tijdelijke kopie (een opgeschoonde svg) die na afloop weg moet
     */
    public function __construct(
        public readonly string $path,
        public readonly string $mime,
        public readonly string $fileName,
        public readonly array $properties,
        public readonly ?array $dimensions,
        public readonly string $checksum,
        public readonly int $size,
        public readonly ?string $ownCopy,
    ) {
    }
}
