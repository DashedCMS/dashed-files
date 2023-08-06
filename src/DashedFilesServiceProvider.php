<?php

namespace Dashed\DashedFiles;

use Dashed\DashedFiles\Filament\Pages\FilesPage;
use Filament\PluginServiceProvider;
use Spatie\LaravelPackageTools\Package;

class DashedFilesServiceProvider extends PluginServiceProvider
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

    protected function getPages(): array
    {
        return array_merge(parent::getPages(), [
            FilesPage::class,
        ]);
    }
}
