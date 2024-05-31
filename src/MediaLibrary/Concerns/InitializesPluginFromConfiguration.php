<?php

namespace Dashed\DashedFiles\MediaLibrary\Concerns;

trait InitializesPluginFromConfiguration
{
    protected static array $configurationMap = [
        'models.item' => 'modelItem',
        'models.folder' => 'modelFolder',
        'disk.visibility' => 'diskVisibility',
        'register.pages' => 'registerPages',
        'register.livewire.upload-media' => 'uploadMediaComponent',
        'register.livewire.media-info' => 'mediaInfoComponent',
        'register.livewire.browse-library' => 'browseLibraryComponent',
        'thumbnail-media-conversion' => 'thumbnailMediaConversion',
        'first-available-url-conversions' => 'firstAvailableUrlConversions',
        'media-picker-conversion' => 'mediaPickerMediaConversion',
        'settings.show-upload-box-by-default' => 'showUploadBoxByDefault',
        'settings.warning-unstored-uploads' => 'unstoredUploadsWarning',
        'accepted_filetypes.image' => 'acceptImage',
        'accepted_filetypes.pdf' => 'acceptPdf',
        'accepted_filetypes.video' => 'acceptVideo',
        'conversions.responsive.enabled' => 'conversionResponsive',
        'conversions.800.enabled' => 'conversionMedium',
        'conversions.800.width' => 'conversionMediumWidth',
        'conversions.400.enabled' => 'conversionSmall',
        'conversions.400.width' => 'conversionSmallWidth',
        'conversions.thumb.enabled' => 'conversionThumb',
        'conversions.thumb.width' => 'conversionThumbWidth',
        'conversions.thumb.height' => 'conversionThumbHeight',
        'modals.media-picker.width' => 'mediaPickerModalWidth',
    ];

    protected function initializePluginFromConfigurationIfPresent(): void
    {
        foreach (static::$configurationMap as $key => $method) {
            $value = config("filament-media-library.{$key}", null);

            if (blank($value)) {
                continue;
            }

            $this->{$method}($value);
        }
    }
}
