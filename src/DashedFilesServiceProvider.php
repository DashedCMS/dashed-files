<?php

namespace Dashed\DashedFiles;

use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Queue;
use Spatie\LaravelPackageTools\Package;
use Filament\Support\Facades\FilamentView;
use Illuminate\Console\Scheduling\Schedule;
use RalphJSmit\Filament\Upload\FilamentUpload;
use Dashed\DashedFiles\Observers\MediaObserver;
use Dashed\DashedFiles\Commands\ClearTempImages;
use Dashed\DashedFiles\Classes\MediaPickerFileCache;
use Dashed\DashedFiles\Commands\CreateConversionUrls;
use Dashed\DashedFiles\Commands\ClearMediaConversions;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Dashed\DashedFiles\Commands\MigrateImagesToNewPath;
use Dashed\DashedFiles\Commands\MigrateImagesInDatabase;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;
use Dashed\DashedFiles\Commands\MigrateFilesToSpatieMediaLibrary;

class DashedFilesServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-files';

    // Bewust bootingPackage() en niet configurePackage(): mediaHelper()->plugin()
    // roept hieronder __('Content') aan voor de navigationGroup, en configurePackage()
    // draait in de register()-fase, vóórdat Laravel's vertaler bestaat. Verplaats dit
    // niet terug naar configurePackage(), dat crasht de hele boot (zie task-5-report.md).
    public function bootingPackage()
    {
        cms()->builder('publishOnUpdate', [
            'medialibrary-config',
        ]);

        cms()->builder('plugins', [
            new DashedFilesPlugin(),
            mediaHelper()->plugin(),
//            FilamentUpload::make(),
        ]);

        if (method_exists(cms(), 'registerContentQualityCheck')) {
            cms()->registerContentQualityCheck(new \Dashed\DashedFiles\ContentQuality\MissingAltTextCheck());
        }

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

        // Script en stijl voor slepen-om-te-uploaden op elke MediaPicker
        // (UploadableMediaPicker). Alleen ingelogd: BODY_END staat ook op de
        // inlogpagina, en daar staat geen picker.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => auth()->check() ? view('dashed-files::filament.media-picker-upload') : '',
        );

        Media::observe(MediaObserver::class);

        // De gedeelde cache van de MediaPicker is statisch: leeg hem aan het
        // eind van elk verzoek, voor en na elke wachtrijtaak (na alleen vuurt
        // niet bij een mislukte taak), en zodra een bestand, map
        // of mediarij verandert, zodat een hernoemd of vervangen bestand in
        // hetzelfde verzoek niet met zijn oude gegevens terugkomt.
        $this->app->terminating(fn () => MediaPickerFileCache::flush());
        Queue::before(fn () => MediaPickerFileCache::flush());
        Queue::after(fn () => MediaPickerFileCache::flush());

        foreach ([MediaLibraryItem::class, MediaLibraryFolder::class, Media::class] as $model) {
            $model::saved(fn () => MediaPickerFileCache::flush());
            $model::deleted(fn () => MediaPickerFileCache::flush());
        }

        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command('dashed:clear-temp-images')->hourly()->withoutOverlapping();
            $schedule->command(CreateConversionUrls::class)->hourly()->withoutOverlapping();
        });
    }

    public function configurePackage(Package $package): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'dashed-files');

        $package
            ->name('dashed-files')
            ->hasTranslations()
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
    }
}
