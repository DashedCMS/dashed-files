<?php

namespace Dashed\DashedFiles\Classes\Sources;

use Dashed\DashedFiles\Classes\Ingest\IngestResult;

/**
 * Een bron waarin gezocht wordt (een stockaanbieder).
 */
interface SearchableMediaSource extends MediaSource
{
    /**
     * @param  array<string, mixed>  $options  orientation
     * @return array{items: array<int, array<string, mixed>>, page: int, hasMore: bool, total: ?int}
     *
     * @throws MediaSourceException
     */
    public function search(string $query, int $page = 1, array $options = [], ?string $siteId = null): array;

    /**
     * Haalt één resultaat naar de bibliotheek. De browser stuurt alleen het
     * id van de aanbieder; het adres van het bestand zoekt de server zelf op.
     *
     * @throws MediaSourceException
     * @throws \Dashed\DashedFiles\Classes\Ingest\MediaRejectedException
     */
    public function import(string $externalId, ?int $folderId = null, ?int $userId = null, string $query = '', ?string $siteId = null): IngestResult;
}
