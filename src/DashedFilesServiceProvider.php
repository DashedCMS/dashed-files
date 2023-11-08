<?php

namespace Dashed\DashedFiles;

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
            ->hasConfigFile([
                'file-manager',
            ]);
    }
}
