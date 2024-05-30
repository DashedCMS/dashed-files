<?php

namespace Dashed\DashedFiles;

use Dashed\DashedFiles\Commands\MigrateFilesToSpatieMediaLibrary;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DashedFilesServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-files';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('dashed-files')
            ->hasViews()
            ->hasCommands([
                MigrateFilesToSpatieMediaLibrary::class,
            ])
            ->hasConfigFile([
                'file-manager',
            ]);
    }
}
