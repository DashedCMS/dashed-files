<?php

namespace Dashed\DashedFiles;

use Filament\Panel;
use Filament\Contracts\Plugin;

class DashedFilesPlugin implements Plugin
{
    public function getId(): string
    {
        return 'dashed-files';
    }

    public function register(Panel $panel): void
    {
        // Altijd geregistreerd, zodat de route bestaat; of de pagina zichtbaar
        // en bereikbaar is beslist hij per verzoek op MediaLibrary::engine().
        $panel->pages([
            \Dashed\DashedFiles\Filament\Pages\MediaLibraryPage::class,
            // Ook altijd: de instellingenkaart wordt alleen op de eigen motor
            // aangemeld, en de pagina weigert zelf op de oude.
            \Dashed\DashedFiles\Filament\Pages\Settings\MediaSettingsPage::class,
        ]);

        // De zip van een selectie: achter de web- en auth-middleware van het
        // paneel (MFA, IP-lijst), en daarbovenop ondertekend.
        $panel->authenticatedRoutes(function (): void {
            \Illuminate\Support\Facades\Route::get('/media/zip/{token}', \Dashed\DashedFiles\Http\Controllers\MediaZipController::class)
                ->name(\Dashed\DashedFiles\Http\Controllers\MediaZipController::ROUTE);

            // Een privé-bestand openen: ondertekend, kort geldig, en alleen
            // met het recht view_media. Het item op id, de variant uit een vaste lijst.
            \Illuminate\Support\Facades\Route::get('/media/private/{item}/{variant?}', \Dashed\DashedFiles\Http\Controllers\MediaPrivateFileController::class)
                ->where('item', '[0-9]{1,18}')
                ->where('variant', '[a-z]{1,20}')
                ->name(\Dashed\DashedFiles\Http\Controllers\MediaPrivateFileController::ROUTE);
        });
    }

    public function boot(Panel $panel): void
    {

    }
}
