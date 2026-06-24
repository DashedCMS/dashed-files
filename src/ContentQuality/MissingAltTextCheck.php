<?php

// packages/dashed/dashed-files/src/ContentQuality/MissingAltTextCheck.php

namespace Dashed\DashedFiles\ContentQuality;

use Illuminate\Support\Collection;
use Dashed\DashedCore\ContentQuality\QualityIssue;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Dashed\DashedCore\ContentQuality\Contracts\ContentQualityCheck;

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
        return $this->query()->count();
    }

    public function items(string $siteId): Collection
    {
        return $this->query()
            ->limit(200)
            ->get()
            ->map(fn (MediaLibraryItem $item) => new QualityIssue(
                checkKey: 'missing_alt',
                title: $item->caption ?: ('#' . $item->id),
                subtitle: 'mist alt-tekst',
                mediaId: $item->id,
            ));
    }

    public function resolutions(): array
    {
        return ['inline', 'ai', 'bulk_ai'];
    }

    protected function query()
    {
        return MediaLibraryItem::withoutGlobalScopes()
            ->where(function ($q) {
                $q->whereNull('alt_text')->orWhere('alt_text', '');
            });
    }
}
