<?php

namespace Dashed\DashedFiles;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use RalphJSmit\Filament\Upload\FilamentUpload;
use Dashed\DashedFiles\Observers\MediaObserver;
use Dashed\DashedFiles\Commands\ClearTempImages;
use Dashed\DashedFiles\Commands\CreateConversionUrls;
use Dashed\DashedFiles\Commands\ClearMediaConversions;
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

        cms()->registerSettingsDocs(
            page: \Dashed\DashedFiles\Filament\Pages\FilesPage::class,
            title: 'Bestanden',
            intro: 'De mediabibliotheek van de website. Hier beheer je alle afbeeldingen, videos en documenten die op de site gebruikt worden.',
            sections: [
                [
                    'heading' => 'Wat zie je hier?',
                    'body' => <<<MARKDOWN
Een ingebedde bestandsbrowser met:

- Een **mappenstructuur** om bestanden te ordenen per onderwerp, project of pagina.
- Een **uploadknop** om nieuwe bestanden toe te voegen.
- Een **detailweergave** per bestand met naam, afmetingen en andere metadata.
- **Acties** om bestanden te hernoemen, verplaatsen of verwijderen.
MARKDOWN,
                ],
                [
                    'heading' => 'Wat kun je hier doen?',
                    'body' => <<<MARKDOWN
- Bladeren door mappen om een bestaand bestand terug te vinden.
- Nieuwe bestanden uploaden vanaf je computer.
- Bestanden verwijderen die je niet meer gebruikt.
- Metadata van een bestand bewerken, bijvoorbeeld de naam of de map waarin het staat.
MARKDOWN,
                ],
            ],
            tips: [
                'Maak duidelijke mappen per onderwerp. Een opgeruimde bibliotheek werkt bij elke upload in je voordeel.',
                'Verwijder een bestand pas als je zeker weet dat het nergens meer op de site gebruikt wordt.',
            ],
        );
    }

    public function packageBooted()
    {
        $this->app->singleton(\Dashed\DashedFiles\Classes\MediaHelper::class);

        Media::observe(MediaObserver::class);

        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command('dashed:clear-temp-images')->daily();
            $schedule->command(CreateConversionUrls::class)->hourly()->withoutOverlapping();
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
                CreateConversionUrls::class,
                MigrateFilesToSpatieMediaLibrary::class,
                MigrateImagesInDatabase::class,
                MigrateImagesToNewPath::class,
                ClearTempImages::class,
                ClearMediaConversions::class,
                \Dashed\DashedFiles\Commands\BackfillMediaDimensionsCommand::class,
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
