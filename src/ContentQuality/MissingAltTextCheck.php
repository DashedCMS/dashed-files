<?php

namespace Dashed\DashedFiles\ContentQuality;

use Illuminate\Support\Collection;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Dashed\DashedFiles\Classes\MediaTexts;
use Dashed\DashedCore\ContentQuality\QualityIssue;
use Dashed\DashedCore\ContentQuality\Contracts\ContentQualityCheck;

/**
 * Afbeeldingen die in minstens één taal van de site geen alt-tekst hebben.
 * Alleen afbeeldingen: een pdf of video heeft er geen. Items in de
 * prullenbak doen niet mee (de scope van het model laat die weg).
 */
class MissingAltTextCheck implements ContentQualityCheck
{
    public function key(): string
    {
        return 'missing_alt';
    }

    public function label(): string
    {
        return 'Alt-tekst ontbreekt';
    }

    public function count(string $siteId): int
    {
        return $this->query($siteId)->count();
    }

    public function items(string $siteId): Collection
    {
        $locales = MediaTexts::localesForSite($siteId);

        return $this->query($siteId)
            ->limit(200)
            ->get()
            ->map(function (MediaItem $item) use ($locales) {
                $missing = $item->missingAltLocales($locales);

                return new QualityIssue(
                    checkKey: 'missing_alt',
                    title: $item->caption ?: ('#' . $item->id),
                    // Bij één taal de tekst van altijd; bij meer talen ook welke.
                    subtitle: count($locales) > 1 ? 'mist alt-tekst: ' . implode(', ', array_map('strtoupper', $missing)) : 'mist alt-tekst',
                    mediaId: $item->id,
                    missingLocales: $missing,
                );
            });
    }

    public function resolutions(): array
    {
        return ['inline', 'ai', 'bulk_ai'];
    }

    protected function query(?string $siteId = null): Builder
    {
        return MediaItem::query()
            ->where(function (Builder $query) {
                // De spiegelkolom; een item van voor de aanvultaak heeft hem nog leeg, dan telt de mediarij.
                $query->where('mime_type', 'like', 'image/%')
                    ->orWhere(fn (Builder $query) => $query->whereNull('mime_type')->whereHas('media', fn (Builder $media) => $media->where('collection_name', MediaItem::COLLECTION)->where('mime_type', 'like', 'image/%')));
            })
            ->missingAltTextInAnyLocale(MediaTexts::localesForSite($siteId));
    }
}
