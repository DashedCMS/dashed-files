<?php

namespace Dashed\DashedFiles\Classes;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Forms\Components\MediaPicker;
use RalphJSmit\Filament\MediaLibrary\Media\DataTransferObjects\MediaItemMeta;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;

class MediaHelper extends Command
{
    public function field($name = 'image', $label = 'Afbeelding', $required = false, $multiple = false, $isImage = false): MediaPicker
    {
        $mediaPicker = MediaPicker::make($name)
            ->label($label)
            ->required($required)
            ->multiple($multiple)
            ->showFileName()
            ->downloadable()
            ->reorderable();

        if ($isImage) {
            $mediaPicker->acceptedFileTypes(['image/*']);
        }

        return $mediaPicker;
    }

    public function plugin()
    {
        return FilamentMediaLibrary::make()
            ->navigationGroup('Content')
            ->navigationSort(1)
            ->navigationLabel('Media Browser')
            ->navigationIcon('heroicon-o-camera')
            ->activeNavigationIcon('heroicon-s-camera')
            ->pageTitle('Media Browser')
            ->acceptPdf()
            ->acceptVideo()
            ->conversionMedium(enabled: false)
            ->conversionSmall(enabled: false)
            ->conversionThumb(enabled: true, width: 600, height: 600)
            ->firstAvailableUrlConversions([
                'thumb',
            ])
            ->slug('media-browser');
    }

    public function getSingleImage(int|string|array $mediaId, string $conversion = 'thumb'): ?MediaItemMeta
    {
        if (is_string($mediaId) && filter_var($mediaId, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        if (is_array($mediaId)) {
            $mediaId = $mediaId[0];
        }

        if (! is_int($mediaId)) {
            $mediaId = (int)$mediaId;
        }

        $media = Cache::rememberForever('media-library-media-' . $mediaId . '-' . $conversion, function () use ($mediaId, $conversion) {
            $media = MediaLibraryItem::find($mediaId);
            $meta = $media->getMeta();
            $mediaItem = $media->getItem();
            $meta->url = $mediaItem->getAvailableUrl([$conversion]);

            return $meta;
        });

        return $media;
    }

    public function getMultipleImages(array $mediaIds, string $conversion = 'thumb'): ?Collection
    {
        if (is_string($mediaIds)) {
            return null;
        }

        if (is_int($mediaIds)) {
            return null;
        }

        $medias = [];

        foreach($mediaIds as $id) {
            $medias[] = $this->getSingleImage($id, $conversion);
        }

        return collect($medias);
    }
}
