<?php

namespace Dashed\DashedFiles\Classes\Sources;

use Dashed\DashedFiles\Models\MediaItem;

/**
 * De herkomst en de naamsvermelding van een item, gelezen uit wat de inname
 * bij het bestand bewaarde (`source` en `source_meta`). Alles hier is tekst
 * van buiten: een naam of titel is nooit HTML, en een adres wordt alleen een
 * link als het https is en op het domein van de aanbieder staat.
 */
final class MediaCredit
{
    public const TEXT_MAX = 200;

    /** Kale tekst: zonder stuurtekens, ingekort. Escapen doet de view. */
    public static function text(mixed $value, int $max = self::TEXT_MAX): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/[\p{C}]+/u', ' ', mb_scrub((string) $value, 'UTF-8')));
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /**
     * Het adres, alleen als het een https-adres is op een van de genoemde
     * hosts (of een subdomein daarvan), zonder inloggegevens en zonder
     * afwijkende poort. Anders null: dan wordt het geen link.
     *
     * @param  array<int, string>  $hosts
     */
    public static function link(mixed $url, array $hosts): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20"\'<>\\\\]/', $url)) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        foreach ($hosts as $allowed) {
            $allowed = strtolower(trim((string) $allowed));

            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                return $url;
            }
        }

        return null;
    }

    /**
     * De vermelding voor een thema, of null als er niets te vermelden valt.
     *
     * @return array{name: ?string, url: ?string, provider: string, provider_url: ?string}|null
     */
    public static function for(?MediaItem $item): ?array
    {
        [$source, $meta] = self::read($item);

        // Een item dat eerst van elders kwam en later ook als stockfoto werd
        // binnengehaald: de eerste vermelding uit de aangevulde lijst.
        if (! str_starts_with($source, 'stock:')) {
            $meta = self::extraCredits($meta)[0] ?? null;

            if ($meta === null) {
                return null;
            }
        }

        $provider = self::text($meta['provider_name'] ?? null, 60);

        if ($provider === null) {
            return null;
        }

        $hosts = self::hosts($meta);
        $name = self::text($meta['author'] ?? null);

        return [
            'name' => $name,
            'url' => $name === null ? null : self::link($meta['author_url'] ?? null, $hosts),
            'provider' => $provider,
            'provider_url' => self::link($meta['page_url'] ?? null, $hosts) ?? self::link($meta['provider_home'] ?? null, $hosts),
        ];
    }

    /**
     * De herkomst voor het detailpaneel. Elke waarde is tekst; `*_url` is
     * een gecontroleerde link of null.
     *
     * @return array<string, ?string>|null
     */
    public static function origin(?MediaItem $item): ?array
    {
        [$source, $meta] = self::read($item);
        $kind = explode(':', $source, 2)[0];

        // Zonder bekende bron (een item van voor de inname) staat er niets: dat is geen uitspraak.
        if ($kind === '') {
            return null;
        }

        if ($kind === 'url') {
            $address = self::displayAddress($meta['source_url'] ?? null);
            $host = $address === null ? null : self::text(parse_url($address, PHP_URL_HOST) ?: null, 120);

            return [
                'kind' => 'url',
                'label' => $host === null ? __('Van een URL') : __('Van URL :host', ['host' => $host]),
                // Als tekst, nooit als link: het adres kwam van een gebruiker.
                // Zonder query, fragment en inloggegevens: daar staan vaak
                // tokens in. Het volledige adres blijft in source_meta.
                'address' => $address,
            ] + self::stockFields($item);
        }

        if ($kind === 'stock') {
            $credit = self::for($item);
            $provider = $credit['provider'] ?? self::text(explode(':', $source, 2)[1] ?? null, 60) ?? __('stock');

            return [
                'kind' => 'stock',
                'label' => __('Stock: :provider', ['provider' => $provider]),
                'provider' => $provider,
                'provider_url' => $credit['provider_url'] ?? null,
                'author' => $credit['name'] ?? null,
                'author_url' => $credit['url'] ?? null,
                'license' => self::text($meta['license'] ?? null, 80),
                'credit' => self::line($credit),
            ];
        }

        if ($kind === 'ai') {
            $prompt = self::text($meta['prompt'] ?? null, 2000);

            return [
                'kind' => 'ai',
                'label' => __('Gemaakt met AI'),
                'prompt' => $prompt === null ? null : (mb_strlen($prompt) > 160 ? mb_substr($prompt, 0, 160) . '...' : $prompt),
                'model' => self::text($meta['model'] ?? null, 80),
            ] + self::stockFields($item);
        }

        if ($kind === 'upload') {
            return ['kind' => 'upload', 'label' => __('Geüpload')] + self::stockFields($item);
        }

        return ['kind' => 'other', 'label' => __('Bron: :source', ['source' => self::text($source, 40) ?? '-'])] + self::stockFields($item);
    }

    /**
     * Schema, host en pad van een adres, zonder poort, query, fragment of
     * inloggegevens. Null als het geen http(s)-adres is.
     */
    public static function displayAddress(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2000) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return self::text($scheme . '://' . $host . (string) ($parts['path'] ?? ''), 500);
    }

    /**
     * Vult de stockvermelding aan bij een item dat dezelfde bytes al had:
     * onder een eigen sleutel (`source_meta.stock`), zonder `source` of
     * `source_url` van de eerste herkomst te raken. Staat dezelfde foto van
     * dezelfde aanbieder er al, dan verandert er niets.
     *
     * @param  array<string, mixed>  $credit  de meta zoals StockSource hem bij een nieuwe import bewaart
     */
    public static function addStockCredit(MediaItem $item, array $credit): void
    {
        rescue(function () use ($item, $credit) {
            $file = $item->file();
            $provider = self::text($credit['provider'] ?? null, 40);
            $externalId = self::text($credit['external_id'] ?? null, 40);

            if (! $file || $provider === null || $externalId === null) {
                return;
            }

            $source = strtolower((string) $file->getCustomProperty('source'));
            $meta = $file->getCustomProperty('source_meta');
            $meta = is_array($meta) ? $meta : [];
            $ref = self::stockRef($provider, $externalId);

            if (($source === 'stock:' . $provider && (string) ($meta['external_id'] ?? '') === $externalId) || in_array($ref, (array) ($meta['stock_refs'] ?? []), true)) {
                return;
            }

            $entry = array_filter([
                'provider' => $provider,
                'provider_name' => self::text($credit['provider_name'] ?? null, 60),
                'provider_home' => is_string($credit['provider_home'] ?? null) ? $credit['provider_home'] : null,
                'link_hosts' => array_values(array_filter((array) ($credit['link_hosts'] ?? []), 'is_string')),
                'external_id' => $externalId,
                'author' => self::text($credit['author'] ?? null, 120),
                'author_url' => is_string($credit['author_url'] ?? null) ? mb_substr($credit['author_url'], 0, 500) : null,
                'page_url' => is_string($credit['page_url'] ?? null) ? mb_substr($credit['page_url'], 0, 500) : null,
                'license' => self::text($credit['license'] ?? null, 80),
            ], fn ($value) => $value !== null && $value !== '' && $value !== []);

            // Een begrensde lijst: de eerste vermelding blijft altijd vooraan.
            $meta['stock'] = array_slice(array_merge(self::extraCredits($meta), [$entry]), 0, 10);
            $meta['stock_refs'] = array_slice(array_values(array_unique(array_merge(array_filter((array) ($meta['stock_refs'] ?? []), 'is_string'), [$ref]))), 0, 10);

            $file->setCustomProperty('source_meta', $meta);
            $file->save();
        }, null, false);
    }

    /** De sleutel waarop de dubbelcontrole een aangevulde stockfoto vindt. */
    public static function stockRef(string $provider, string $externalId): string
    {
        return 'stock:' . strtolower($provider) . ':' . $externalId;
    }

    /**
     * Of dit item (als eerste herkomst of als aangevulde vermelding) de
     * foto met dit id van deze aanbieder is.
     */
    public static function hasStockPhoto(?MediaItem $item, string $provider, string $externalId): bool
    {
        [$source, $meta] = self::read($item);

        return ($source === 'stock:' . strtolower($provider) && (string) ($meta['external_id'] ?? '') === $externalId)
            || in_array(self::stockRef($provider, $externalId), (array) ($meta['stock_refs'] ?? []), true);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, array<string, mixed>>
     */
    private static function extraCredits(array $meta): array
    {
        return array_values(array_filter(is_array($meta['stock'] ?? null) ? $meta['stock'] : [], fn ($entry) => is_array($entry) && self::text($entry['provider_name'] ?? null, 60) !== null));
    }

    /**
     * De velden van een aangevulde stockvermelding naast een andere herkomst.
     *
     * @return array<string, ?string>
     */
    private static function stockFields(?MediaItem $item): array
    {
        [, $meta] = self::read($item);
        $first = self::extraCredits($meta)[0] ?? null;
        $credit = $first === null ? null : self::for($item);

        if ($credit === null) {
            return [];
        }

        return [
            'provider' => $credit['provider'],
            'provider_url' => $credit['provider_url'],
            'author' => $credit['name'],
            'author_url' => $credit['url'],
            'license' => self::text($first['license'] ?? null, 80),
            'credit' => self::line($credit),
        ];
    }

    /**
     * "Foto van <maker> via <aanbieder>", als kale tekst (voor het bijschrift).
     *
     * @param  array{name: ?string, provider: string}|null  $credit
     */
    public static function line(?array $credit): ?string
    {
        if ($credit === null) {
            return null;
        }

        return $credit['name'] === null
            ? __('Foto via :provider', ['provider' => $credit['provider']])
            : __('Foto van :name via :provider', ['name' => $credit['name'], 'provider' => $credit['provider']]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function read(?MediaItem $item): array
    {
        $file = $item ? rescue(fn () => $item->file(), null, false) : null;
        $source = (string) ($file?->getCustomProperty('source') ?: $item?->getAttribute('source') ?: '');
        $meta = $file?->getCustomProperty('source_meta');

        return [strtolower($source), is_array($meta) ? $meta : []];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, string>
     */
    private static function hosts(array $meta): array
    {
        return array_values(array_filter(is_array($meta['link_hosts'] ?? null) ? $meta['link_hosts'] : [], 'is_string'));
    }
}
