<?php

namespace Dashed\DashedFiles;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use RalphJSmit\Filament\Upload\FilamentUpload;
use Dashed\DashedFiles\Observers\MediaObserver;
use Dashed\DashedFiles\Commands\ClearTempImages;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Dashed\DashedFiles\Commands\MigrateImagesToNewPath;
use Dashed\DashedFiles\Commands\MigrateImagesInDatabase;
use Dashed\DashedFiles\Commands\MigrateFilesToSpatieMediaLibrary;

class DashedFilesServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-files';

    public function bootingPackage()
    {
        cms()->builder('publishOnUpdate', [
            'medialibrary-config',
        ]);
    }

    public function packageBooted()
    {
        Media::observe(MediaObserver::class);

        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command('dashed:clear-temp-images')->daily();
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
                MigrateImagesToNewPath::class,
                ClearTempImages::class,
            ])
            ->hasConfigFile([
                'media-library',
            ]);

        cms()->builder('plugins', [
            new DashedFilesPlugin(),
            mediaHelper()->plugin(),
//            FilamentUpload::make(),
        ]);
    }
}
