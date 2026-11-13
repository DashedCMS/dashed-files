<?php

namespace Dashed\DashedFiles\Classes\Sources;

use Closure;
use Throwable;
use Dashed\DashedFiles\Classes\Access\MediaSites;

/**
 * De externe bronnen van de mediabibliotheek. Een pakket meldt zijn bron in
 * de boot-fase aan, achter class_exists:
 *
 *   app(MediaSourceRegistry::class)->register('pexels', fn () => new PexelsSource());
 *
 * De closure wordt pas bij het eerste gebruik aangeroepen, zodat er tijdens
 * de boot niets vertaald of uit de database gelezen wordt. Een bron die niet
 * beschikbaar is bestaat voor het venster niet: geen tabblad, en de server
 * weigert zijn methodes.
 */
class MediaSourceRegistry
{
    /** @var array<string, Closure|MediaSource> */
    private array $sources = [];

    public function register(string $key, Closure|MediaSource $source): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $key)) {
            throw new \InvalidArgumentException("Invalid media source key [{$key}].");
        }

        // Dezelfde sleutel vervangt: een ServiceProvider die twee keer boot levert geen dubbele tab.
        $this->sources[$key] = $source;
    }

    public function forget(string $key): void
    {
        unset($this->sources[$key]);
    }

    /**
     * Elke aangemelde bron, ook wat nu niet beschikbaar is (voor de doctor).
     *
     * @return array<string, MediaSource>
     */
    public function all(): array
    {
        $all = [];

        foreach (array_keys($this->sources) as $key) {
            if ($source = $this->resolve($key)) {
                $all[$key] = $source;
            }
        }

        return $all;
    }

    /**
     * De bronnen die op deze site nu te gebruiken zijn.
     *
     * @param  array<int, string>|null  $only  de beperking van een veld; null is alles, een lege lijst niets
     * @return array<string, MediaSource>
     */
    public function available(?string $siteId = null, ?array $only = null): array
    {
        $siteId ??= self::siteId();

        return array_filter($this->all(), function (MediaSource $source) use ($siteId, $only) {
            if ($only !== null && ! in_array($source->key(), $only, true)) {
                return false;
            }

            // Een kapotte bron van een ander pakket mag het venster niet meenemen.
            return (bool) rescue(fn () => $source->isAvailable($siteId), false);
        });
    }

    /** De bron op naam, alleen als hij nu beschikbaar is. */
    public function find(string $key, ?string $siteId = null, ?array $only = null): ?MediaSource
    {
        return $this->available($siteId, $only)[$key] ?? null;
    }

    /** De site waarvan de instellingen gelden: de actieve, anders de eerste. */
    public static function siteId(): ?string
    {
        return MediaSites::active() ?? (array_key_first(MediaSites::all()) === null ? null : (string) array_key_first(MediaSites::all()));
    }

    private function resolve(string $key): ?MediaSource
    {
        $source = $this->sources[$key] ?? null;

        if ($source instanceof Closure) {
            try {
                $source = $source();
            } catch (Throwable $exception) {
                report($exception);

                return null;
            }

            if ($source instanceof MediaSource) {
                $this->sources[$key] = $source;
            }
        }

        return $source instanceof MediaSource && $source->key() === $key && in_array($source->kind(), MediaSource::KINDS, true) ? $source : null;
    }
}
