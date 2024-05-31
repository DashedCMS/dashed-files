<?php

namespace Dashed\DashedFiles;

use Dashed\DashedFiles\Commands\MigrateFilesToSpatieMediaLibrary;
use Dashed\DashedFiles\Commands\MigrateImagesInDatabase;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DashedFilesServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-files';

    public function configurePackage(Package $package): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $package
            ->name('dashed-files')
            ->hasViews()
            ->hasCommands([
                MigrateFilesToSpatieMediaLibrary::class,
                MigrateImagesInDatabase::class,
            ])
            ->hasConfigFile([
                'file-manager',
            ]);
    }
}
