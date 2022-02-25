<?php

namespace Qubiqx\QcommerceFiles;

use Filament\PluginServiceProvider;
use Qubiqx\QcommerceFiles\Filament\Pages\FilesPage;
use Spatie\LaravelPackageTools\Package;

class QcommerceFilesServiceProvider extends PluginServiceProvider
{
    public static string $name = 'qcommerce-files';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('qcommerce-files')
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
