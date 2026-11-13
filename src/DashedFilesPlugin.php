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

        // Stijl en script van de mediabibliotheek: statisch, met de hash van de
        // inhoud in de URL en dus een jaar te bewaren. Binnen de paneelroutes
        // (de IP-lijst blijft gelden), maar zonder inlog: er staat niets in
        // wat niet in het pakket zelf staat.
        $panel->routes(function (): void {
            \Illuminate\Support\Facades\Route::get('/media-assets/{version}/dashed-media.{file}', \Dashed\DashedFiles\Http\Controllers\MediaAssetController::class)
                ->where('version', '[a-f0-9]{12}')
                ->where('file', 'css|js')
                ->name(\Dashed\DashedFiles\Classes\MediaAssets::ROUTE);
        });

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
