<?php

namespace Dashed\DashedFiles;

use Dashed\DashedFiles\Commands\MigrateFilesToSpatieMediaLibrary;
use Dashed\DashedFiles\Commands\MigrateImagesInDatabase;
use RalphJSmit\Filament\MediaLibrary\Facades\MediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DashedFilesServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-files';

    public function bootingPackage()
    {
        MediaLibrary::registerMediaConversions(function (MediaLibraryItem $mediaLibraryItem, Media $media = null) {
            $mediaLibraryItem
                ->addMediaConversion('huge')
                ->format('webp')
                ->width(1600);
            $mediaLibraryItem
                ->addMediaConversion('large')
                ->format('webp')
                ->width(1200);
            $mediaLibraryItem
                ->addMediaConversion('medium')
                ->format('webp')
                ->width(800);
            $mediaLibraryItem
                ->addMediaConversion('small')
                ->format('webp')
                ->width(400);
            $mediaLibraryItem
                ->addMediaConversion('tiny')
                ->format('webp')
                ->width(200);
        });
    }

    public function configurePackage(Package $package): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'dashed-files');

        $package
            ->name('dashed-files')
            ->hasViews()
            ->hasCommands([
                MigrateFilesToSpatieMediaLibrary::class,
                MigrateImagesInDatabase::class,
            ])
            ->hasConfigFile([
                'media-library',
            ]);
    }
}
