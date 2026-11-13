<x-filament-panels::page>
    @include('dashed-files::media.browser', [
        'mediaConfig' => array_merge($this->mediaBrowserConfig('manage'), ['openItem' => $this->openItem]),
        'mediaItemActions' => $this->mediaActionButtons(\Dashed\DashedFiles\Classes\MediaActionRegistry::ITEM),
        'mediaBulkActions' => $this->mediaActionButtons(\Dashed\DashedFiles\Classes\MediaActionRegistry::BULK),
        'mediaToolbarActions' => $this->mediaActionButtons(\Dashed\DashedFiles\Classes\MediaActionRegistry::TOOLBAR),
    ])
</x-filament-panels::page>
