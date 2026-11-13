<?php

namespace Dashed\DashedFiles\Classes\Sources\Stock;

/**
 * Pixabay. De API kent alleen de sleutel als queryparameter; daarom vangt
 * StockSource::get() elke fout af zonder de oorspronkelijke melding (met het
 * adres erin) door te geven of te loggen. Voorwaarden (nagelezen op 13
 * november 2026): blijvend hotlinken mag niet, eerst naar de eigen server
 * downloaden; zoekresultaten 24 uur in de cache; in het zoekscherm tonen
 * waar de beelden vandaan komen; 100 verzoeken per minuut per sleutel. De
 * adressen in een antwoord zijn 24 uur geldig, net zo lang als de cache.
 * Zonder goedgekeurde volledige toegang is de grootste maat 1280 px.
 */
class PixabaySource extends StockSource
{
    public const KEY = 'pixabay';

    private const ORIENTATION = ['landscape' => 'horizontal', 'portrait' => 'vertical'];

    public function key(): string
    {
        return self::KEY;
    }

    public function providerName(): string
    {
        return 'Pixabay';
    }

    public function providerHome(): string
    {
        return 'https://pixabay.com';
    }

    public function licenseName(): string
    {
        return 'Pixabay Content License';
    }

    public function siteHosts(): array
    {
        return ['pixabay.com'];
    }

    public function defaultHourlyLimit(): int
    {
        return 3000;
    }

    public function fileHosts(): array
    {
        return ['pixabay.com', 'cdn.pixabay.com'];
    }

    public function browserConfig(?string $siteId = null): array
    {
        $config = parent::browserConfig($siteId);
        // Pixabay kent geen vierkant.
        $config['orientations'] = array_values(array_filter($config['orientations'], fn (array $option) => $option['value'] !== 'square'));

        return $config;
    }

    /** Ligt vast; alleen een rookscript overschrijft dit in een subklasse. */
    protected function baseUrl(): string
    {
        return 'https://pixabay.com/api/';
    }

    protected function fetchPage(string $query, int $page, array $options, string $apiKey, int $maxWidth): array
    {
        $body = $this->get($this->baseUrl(), array_filter([
            'key' => $apiKey,
            'q' => $query,
            'image_type' => 'photo',
            'safesearch' => 'true',
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'orientation' => self::ORIENTATION[$options['orientation'] ?? ''] ?? null,
        ]));

        $hits = is_array($body['hits'] ?? null) ? $body['hits'] : [];
        $total = is_numeric($body['totalHits'] ?? null) ? (int) $body['totalHits'] : null;

        return [
            'items' => array_map(fn ($hit) => is_array($hit) ? $this->normalize($hit) : null, $hits),
            'hasMore' => $total !== null && $page * self::PER_PAGE < $total,
            'total' => $total,
        ];
    }

    protected function fetchOne(string $externalId, string $apiKey, int $maxWidth): ?array
    {
        if (! ctype_digit($externalId)) {
            return null;
        }

        $body = $this->get($this->baseUrl(), ['key' => $apiKey, 'id' => $externalId]);
        $hit = is_array($body['hits'][0] ?? null) ? $body['hits'][0] : null;

        return $hit === null ? null : $this->normalize($hit);
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return array<string, mixed>
     */
    protected function normalize(array $hit): array
    {
        $user = is_string($hit['user'] ?? null) ? $hit['user'] : null;
        $userId = is_numeric($hit['user_id'] ?? null) ? (int) $hit['user_id'] : null;

        return [
            'id' => $hit['id'] ?? null,
            'thumb' => $hit['webformatURL'] ?? ($hit['previewURL'] ?? null),
            'preview' => $hit['largeImageURL'] ?? null,
            'download' => $hit['largeImageURL'] ?? ($hit['webformatURL'] ?? null),
            'width' => $hit['imageWidth'] ?? null,
            'height' => $hit['imageHeight'] ?? null,
            'author' => $user,
            // De API geeft geen profieladres; dit is de vaste vorm ervan.
            'author_url' => $user !== null && $userId !== null ? 'https://pixabay.com/users/' . rawurlencode($user) . '-' . $userId . '/' : null,
            'page_url' => $hit['pageURL'] ?? null,
            'alt' => $hit['tags'] ?? null,
        ];
    }
}
