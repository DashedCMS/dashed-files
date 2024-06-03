<?php

namespace Dashed\DashedFiles;

use Dashed\DashedFiles\Commands\MigrateFilesToSpatieMediaLibrary;
use Dashed\DashedFiles\Commands\MigrateImagesInDatabase;
use Illuminate\Support\Facades\Blade;
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
                ->width(1600);
            $mediaLibraryItem
                ->addMediaConversion('large')
                ->width(1200);
            $mediaLibraryItem
                ->addMediaConversion('medium')
                ->width(800);
            $mediaLibraryItem
                ->addMediaConversion('small')
                ->width(400);
            $mediaLibraryItem
                ->addMediaConversion('tiny')
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
