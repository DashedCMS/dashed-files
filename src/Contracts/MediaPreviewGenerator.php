<?php

namespace Dashed\DashedFiles\Contracts;

/**
 * Leest metadata uit een video of pdf en maakt er een voorvertoning van
 * (poster of miniatuur). Draait op bestanden van gebruikers: een
 * implementatie krijgt alleen een lokale kopie onder een naam die wij
 * kozen, gooit nooit een exception en vertrouwt de uitvoer van een extern
 * programma niet.
 */
interface MediaPreviewGenerator
{
    /** Of deze generator iets kan met een bestand van dit (gesnoven) type. */
    public function supports(string $mime): bool;

    /** Of het programma waar deze generator op leunt hier echt kan draaien. */
    public function isAvailable(): bool;

    /**
     * @return array{width: ?int, height: ?int, duration: ?float, pages: ?int} null waar onbekend
     */
    public function probe(string $localPath): array;

    /** Schrijft een jpeg naar $targetPath. False als dat niet lukte. */
    public function poster(string $localPath, string $targetPath): bool;
}
