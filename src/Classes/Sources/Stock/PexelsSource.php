<?php

namespace Dashed\DashedFiles\Classes\Sources\Stock;

/**
 * Pexels. De sleutel gaat als Authorization-header mee, nooit in het adres.
 * Voorwaarden (nagelezen op 13 november 2026): downloaden en een eigen kopie
 * bewaren mag, er is geen hotlink-plicht; een zichtbare link naar Pexels in
 * het zoekscherm en waar mogelijk de naam van de fotograaf; standaard 200
 * verzoeken per uur en 20.000 per maand per sleutel.
 */
class PexelsSource extends StockSource
{
    public const KEY = 'pexels';

    public function key(): string
    {
        return self::KEY;
    }

    public function providerName(): string
    {
        return 'Pexels';
    }

    public function providerHome(): string
    {
        return 'https://www.pexels.com';
    }

    public function licenseName(): string
    {
        return 'Pexels License';
    }

    public function siteHosts(): array
    {
        return ['pexels.com'];
    }

    public function defaultHourlyLimit(): int
    {
        return 150;
    }

    public function fileHosts(): array
    {
        return ['images.pexels.com'];
    }

    /** Ligt vast; alleen een rookscript overschrijft dit in een subklasse. */
    protected function baseUrl(): string
    {
        return 'https://api.pexels.com/v1';
    }

    protected function fetchPage(string $query, int $page, array $options, string $apiKey, int $maxWidth): array
    {
        $body = $this->get($this->baseUrl() . '/search', array_filter([
            'query' => $query,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'orientation' => $options['orientation'] ?? null,
        ]), ['Authorization' => $apiKey]);

        $photos = is_array($body['photos'] ?? null) ? $body['photos'] : [];

        return [
            'items' => array_map(fn ($photo) => is_array($photo) ? $this->normalize($photo, $maxWidth) : null, $photos),
            'hasMore' => is_string($body['next_page'] ?? null) && $body['next_page'] !== '',
            'total' => $body['total_results'] ?? null,
        ];
    }

    protected function fetchOne(string $externalId, string $apiKey, int $maxWidth): ?array
    {
        if (! ctype_digit($externalId)) {
            return null;
        }

        $photo = $this->get($this->baseUrl() . '/photos/' . $externalId, [], ['Authorization' => $apiKey]);

        return isset($photo['id']) ? $this->normalize($photo, $maxWidth) : null;
    }

    /**
     * @param  array<string, mixed>  $photo
     * @return array<string, mixed>
     */
    protected function normalize(array $photo, int $maxWidth): array
    {
        $src = is_array($photo['src'] ?? null) ? $photo['src'] : [];
        $width = is_numeric($photo['width'] ?? null) ? (int) $photo['width'] : 0;
        $original = is_string($src['original'] ?? null) ? $src['original'] : null;

        // Niet het origineel van tientallen megapixels: de beeldserver van
        // Pexels verkleint op verzoek. Een foto die al smal genoeg is blijft zoals hij is.
        $download = $original !== null && $width > $maxWidth
            ? strtok($original, '?') . '?auto=compress&cs=tinysrgb&w=' . $maxWidth
            : $original;

        return [
            'id' => $photo['id'] ?? null,
            'thumb' => $src['medium'] ?? ($src['small'] ?? null),
            'preview' => $src['large'] ?? null,
            'download' => $download,
            'width' => $photo['width'] ?? null,
            'height' => $photo['height'] ?? null,
            'author' => $photo['photographer'] ?? null,
            'author_url' => $photo['photographer_url'] ?? null,
            'page_url' => $photo['url'] ?? null,
            'alt' => $photo['alt'] ?? null,
        ];
    }
}
