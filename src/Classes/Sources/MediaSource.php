<?php

namespace Dashed\DashedFiles\Classes\Sources;

/**
 * Een externe bron van de mediabibliotheek: een tabblad in het keuzevenster
 * en een knop op de beheerpagina. Een pakket meldt zijn bron in de boot-fase
 * aan bij MediaSourceRegistry.
 *
 * Wat een bron ophaalt gaat altijd door MediaLibrary::ingest() of
 * importFromUrl(); een bron bewaart zelf nooit een bestand.
 */
interface MediaSource
{
    public const KIND_URL = 'url';

    public const KIND_SEARCH = 'search';

    public const KIND_GENERATE = 'generate';

    public const KINDS = [self::KIND_URL, self::KIND_SEARCH, self::KIND_GENERATE];

    /** Unieke naam: a-z, 0-9 en streepje. */
    public function key(): string;

    public function label(): string;

    /** Een heroicon-naam. */
    public function icon(): string;

    /** Sleutel ingevuld, pakket aanwezig, instelling aan. Doet nooit een aanroep naar buiten. */
    public function isAvailable(?string $siteId = null): bool;

    /** url, search of generate: bepaalt welk paneel het venster toont. */
    public function kind(): string;

    /**
     * Wat het paneel van deze bron in de browser nodig heeft (teksten,
     * keuzelijsten, de vermelding van de aanbieder). Nooit een sleutel.
     *
     * @return array<string, mixed>
     */
    public function browserConfig(?string $siteId = null): array;

    /**
     * Voor de doctor: wat er aan- of uitstaat, zonder een aanroep naar buiten
     * en zonder de sleutel zelf.
     *
     * @return array<string, bool|string|int|null>
     */
    public function diagnostics(?string $siteId = null): array;
}
