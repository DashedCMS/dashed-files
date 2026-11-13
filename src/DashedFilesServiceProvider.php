<?php

namespace Dashed\DashedFiles;

use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Queue;
use Spatie\LaravelPackageTools\Package;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Models\MediaFolder;
use Filament\Support\Facades\FilamentView;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Observers\MediaObserver;
use Dashed\DashedFiles\Commands\ClearTempImages;
use Dashed\DashedFiles\Classes\Browse\MediaTagging;
use Dashed\DashedFiles\Classes\MediaActionRegistry;
use Dashed\DashedFiles\Commands\MediaDoctorCommand;
use Dashed\DashedFiles\Classes\MediaPickerFileCache;
use Dashed\DashedFiles\Commands\CreateConversionUrls;
use Dashed\DashedFiles\Classes\Browse\MediaFolderTree;
use Dashed\DashedFiles\Commands\ClearMediaConversions;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Dashed\DashedFiles\Classes\Usage\MediaUsageIndexer;
use Dashed\DashedFiles\Classes\Usage\MediaUsageRegistry;
use Dashed\DashedFiles\Classes\Legacy\LegacyClassAliases;

class DashedFilesServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-files';

    // Het grootste aantal alt-teksten dat één bulkactie laat genereren.
    public const ALT_TEXT_BULK_MAX = 200;

    // Na zoveel pogingen zonder bruikbaar antwoord probeert de AI het niet meer uit zichzelf.
    public const ALT_TEXT_MAX_TRIES = 3;

    // Bewust bootingPackage() en niet configurePackage(): mediaHelper()->plugin()
    // roept hieronder __('Content') aan voor de navigationGroup, en configurePackage()
    // draait in de register()-fase, vóórdat Laravel's vertaler bestaat. Verplaats dit
    // niet terug naar configurePackage(), dat crasht de hele boot (zie task-5-report.md).
    public function bootingPackage()
    {
        cms()->builder('publishOnUpdate', [
            'medialibrary-config',
        ]);

        // Op de eigen motor heeft geen enkel formulier de plugin van het
        // pakket nog nodig; zonder die plugin verdwijnt ook de oude
        // bibliotheekpagina uit het paneel en de navigatie. De stand bij het
        // opstarten telt: wie omschakelt terwijl de routes gecachet zijn,
        // bouwt de routecache opnieuw.
        $ownEngine = MediaLibrary::usesOwnEngine();
        // Niet voor de rest van het verzoek onthouden: wie de motor na het
        // opstarten omzet (een test, een commando) moet de nieuwe stand lezen.
        MediaLibrary::forgetEngine();
        \Dashed\DashedFiles\Filament\Pages\MediaLibraryPage::$ownsLegacySlug = $ownEngine;

        cms()->builder('plugins', $ownEngine
            ? [new DashedFilesPlugin()]
            : [new DashedFilesPlugin(), mediaHelper()->plugin()]);

        // De instellingen van de eigen bibliotheek (AVIF, prullenbak,
        // ontbrekende formaten). Op de oude motor is er niets in te stellen.
        if ($ownEngine) {
            cms()->registerSettingsPage(
                \Dashed\DashedFiles\Filament\Pages\Settings\MediaSettingsPage::class,
                'Media',
                'photo',
                'AVIF, de prullenbak van de mediabibliotheek en ontbrekende afbeeldingsformaten'
            );
        }

        if (method_exists(cms(), 'registerContentQualityCheck')) {
            cms()->registerContentQualityCheck(new \Dashed\DashedFiles\ContentQuality\MissingAltTextCheck());
        }

        // Als onderwerp en niet als instellingendocs: de pagina waar deze
        // uitleg aan hing (FilesPage) was nooit in het paneel geregistreerd.
        cms()->registerDocTopic(
            key: 'media-library',
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
        $this->app->singleton(\Dashed\DashedFiles\Classes\MediaLibrary::class);
        $this->app->singleton(\Dashed\DashedFiles\Classes\Sources\MediaSourceRegistry::class);
        self::registreerMediabronnen();

        // Een pagina laadt de media van zijn blokken in één cacheronde voor.
        // Een view composer op de component van dashed-core, zodat dashed-core
        // zelf niets hoeft te weten; de nesting (een globaal blok) loopt langs
        // dezelfde component en voor elk niveau komt er één ronde bij.
        View::composer('dashed-core::components.content-blocks', function ($view) {
            rescue(fn () => app(\Dashed\DashedFiles\Classes\MediaHelper::class)->preloadContentMedia($view->getData()['content'] ?? null), report: false);
        });

        // De schijf voor privé-bestanden, als de applicatie hem niet zelf definieert.
        \Dashed\DashedFiles\Classes\Access\MediaStorage::register();

        // Script en stijl voor slepen-om-te-uploaden op elke MediaPicker
        // (UploadableMediaPicker). Alleen ingelogd: BODY_END staat ook op de
        // inlogpagina, en daar staat geen picker.
        // Op de eigen motor staat daar het keuzevenster (één per pagina) in
        // plaats van het script van de oude kiezer.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => match (true) {
                ! auth()->check() => '',
                MediaLibrary::usesOwnEngine() => view('dashed-files::media.picker-host'),
                default => view('dashed-files::filament.media-picker-upload'),
            },
        );

        \Livewire\Livewire::component(\Dashed\DashedFiles\Livewire\MediaPickerModal::COMPONENT, \Dashed\DashedFiles\Livewire\MediaPickerModal::class);

        Media::observe(MediaObserver::class);
        // Alleen actief terwijl de pomp een item verwerkt; zie de methode.
        Media::saving(fn (Media $media) => \Dashed\DashedFiles\Classes\Conversions\ConversionPump::mergeGeneratedConversions($media));

        static::registerMediaActions();

        // De gedeelde cache van de MediaPicker is statisch: leeg hem aan het
        // eind van elk verzoek, voor en na elke wachtrijtaak (na alleen vuurt
        // niet bij een mislukte taak), en zodra een bestand, map
        // of mediarij verandert, zodat een hernoemd of vervangen bestand in
        // hetzelfde verzoek niet met zijn oude gegevens terugkomt.
        $this->app->terminating(fn () => MediaPickerFileCache::flush());
        Queue::before(fn () => static::flushBetweenJobs());
        Queue::after(fn () => static::flushBetweenJobs());

        // De eigen modellen en die van het pakket schrijven naar dezelfde
        // tabellen; tot het pakket weg is moeten beide de cache legen. Is het
        // pakket weg, dan is de oude naam een alias van ons model
        // (LegacyClassAliases) en zou elke haak dubbel hangen.
        $packageItem = LegacyClassAliases::packageClass(LegacyClassAliases::ITEM);
        $packageFolder = LegacyClassAliases::packageClass(LegacyClassAliases::FOLDER);

        foreach (array_filter([MediaItem::class, MediaFolder::class, $packageItem, $packageFolder, Media::class]) as $model) {
            $model::saved(fn () => MediaPickerFileCache::flush());
            $model::deleted(fn () => MediaPickerFileCache::flush());
        }

        // Welke mappen beschermd zijn (chat, orders) staat per verzoek onthouden.
        foreach (array_filter([MediaFolder::class, $packageFolder]) as $model) {
            $model::saved(fn () => \Dashed\DashedFiles\Classes\MediaPrivacy::forget());
            $model::deleted(fn () => \Dashed\DashedFiles\Classes\MediaPrivacy::forget());
            // Net als welke mappen privé zijn en bij welke sites ze horen.
            $model::saved(fn () => \Dashed\DashedFiles\Classes\Access\MediaVisibility::forget());
            $model::deleted(fn () => \Dashed\DashedFiles\Classes\Access\MediaVisibility::forget());
        }

        static::bewaakPriveSchijf();
        \Dashed\DashedFiles\Classes\Access\LockedFieldStates::register();

        // Elke schrijver van Spatie (de pomp, een losse generatie) toetst vlak
        // voor het wegschrijven of de schijf van de mediarij nog dezelfde is.
        $this->app->bind(\Spatie\MediaLibrary\MediaCollections\Filesystem::class, \Dashed\DashedFiles\Classes\Access\GuardedMediaFilesystem::class);

        // Alt-tekst en bijschrift zitten in het gecachete resultaat van
        // getSingleMedia(). De bewerkmodal van het pakket en oudere code
        // schrijven rechtstreeks op het model, buiten MediaLibrary::setText()
        // om; zonder deze haak blijft de oude tekst op de site staan.
        // invalidate() werkt met query-updates en vuurt zelf geen events.
        foreach (array_filter([MediaItem::class, $packageItem]) as $model) {
            // De tekstkolom is de spiegel van de eerste taal in de
            // vertalingen. Wie maar een van de twee schrijft (het oude
            // pakket, klantcode die de kolom rechtstreeks bijwerkt) krijgt de
            // andere hier bijgewerkt, in dezelfde save.
            $model::saving(fn ($item) => \Dashed\DashedFiles\Classes\MediaTexts::mirror($item));

            $model::saved(function ($item) {
                if ($item->wasChanged(['alt_text', 'caption', 'title', 'alt_texts', 'captions', 'titles'])) {
                    app(MediaLibrary::class)->invalidate($item, 'text');
                }
            });
        }

        // Alleen het pakketmodel: dat verwijdert altijd definitief. Het eigen
        // model gooit eerst in de prullenbak, waar de site het item blijft
        // tonen en tags en favorieten blijven staan; pas zijn forceDeleted-haak
        // (MediaItem::booted) ruimt op.
        // Het object en niet het id: de rij is al weg, dus de namen van
        // wat er gecachet staat komen van de attributen.
        // Zonder pakket is de oude naam ons eigen model, en dan zou een
        // gewone prullenbak-delete hier de gebruiksindex en de tags wissen.
        if ($packageItem !== null) {
            $packageItem::deleted(fn ($item) => app(MediaLibrary::class)->invalidate($item, 'deleted'));
            // Tags en favorieten hebben geen foreign key naar de itemtabel.
            $packageItem::deleted(fn ($item) => app(MediaTagging::class)->forgetItems([$item->getKey()]));
            $packageItem::deleted(fn ($item) => rescue(fn () => app(MediaUsageIndexer::class)->forgetTarget((int) $item->getKey())));
        }

        // Een "nee, die kolom is er niet" wordt per verzoek onthouden. Na een
        // migratie in hetzelfde proces (migrate, een test) klopt dat niet meer.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\MigrationsEnded::class, fn () => \Dashed\DashedFiles\Classes\Browse\BrowseSchema::forget());

        // De schema-wandeling van de gebruiksindex: één keer per verzoek of taak.
        $this->app->scoped(\Dashed\DashedFiles\Classes\Usage\MediaFieldMap::class);

        static::registreerMediagebruik();
        static::registreerPrullenbak();
        static::registreerBewaartermijnen();
        static::registreerRechten();
        static::registreerBeveiligingscheck();

        // De gebruiksindex bijwerken. Deze luisteraar vuurt voor ELK model in
        // het CMS: de indexer begint daarom met een opzoeking op klassenaam en
        // doet voor een niet-aangemeld model verder niets. Een fout hier mag
        // een save nooit laten mislukken.
        \Illuminate\Support\Facades\Event::listen('eloquent.saved: *', function (string $event, array $payload): void {
            if (($payload[0] ?? null) instanceof \Illuminate\Database\Eloquent\Model && MediaUsageRegistry::resolve($payload[0]::class) !== null) {
                rescue(fn () => app(MediaUsageIndexer::class)->handleSaved($payload[0]));
            }
        });
        \Illuminate\Support\Facades\Event::listen('eloquent.deleted: *', function (string $event, array $payload): void {
            if (($payload[0] ?? null) instanceof \Illuminate\Database\Eloquent\Model && MediaUsageRegistry::resolve($payload[0]::class) !== null) {
                rescue(fn () => app(MediaUsageIndexer::class)->handleDeleted($payload[0]));
            }
        });

        // Het pad van een map (/3/17/42/) hoort bij zijn plek in de boom.
        // Ook voor het pakketmodel, dat de kolom niet kent: de boom vult
        // een ontbrekend pad zelf aan, maar een subboom-filter leest het direct.
        foreach (array_filter([MediaFolder::class, $packageFolder]) as $model) {
            $model::saved(function ($folder) {
                if ($folder->wasRecentlyCreated || $folder->wasChanged('parent_id')) {
                    app(MediaFolderTree::class)->syncPath((int) $folder->getKey());
                }
            });
        }

        // Na de boot van elk pakket, dus ook na dat van het oude pakket, dat
        // dezelfde alias aanmeldt: wie het laatst komt wint.
        $this->app->booted(fn () => static::mapMorphAlias());
        $this->app->booted(fn () => static::forwardLegacyPolicies());

        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command('dashed:clear-temp-images')
                ->hourlyAt(class_exists(\Dashed\DashedCore\Classes\ScheduleSpread::class) ? \Dashed\DashedCore\Classes\ScheduleSpread::minute('clear-temp-images') : 0)
                ->withoutOverlapping()
                ->when(fn () => ClearTempImages::hasWork());
            // De nachtelijke reparatie. Op de eigen motor een `missing`-run
            // van de pomp, en alleen als er echt iets ontbreekt: één taak
            // tegelijk in de wachtrij, binnen een tijdsbudget. Op de oude
            // motor blijft het commando van altijd staan.
            $spreadMinute = fn (string $task, int $window, int $fallback) => class_exists(\Dashed\DashedCore\Classes\ScheduleSpread::class)
                ? \Dashed\DashedCore\Classes\ScheduleSpread::minute($task, $window)
                : $fallback;

            // Op een minuut die per site verschilt: niet elke site op dezelfde server om 04:30.
            $schedule->command('dashed:media-conversions missing --if-needed')
                ->dailyAt('04:' . str_pad((string) $spreadMinute('media-conversions', 55, 30), 2, '0', STR_PAD_LEFT))
                ->withoutOverlapping()
                ->when(fn () => MediaLibrary::usesOwnEngine());

            // De minuuttaak van de pomp, alleen als er een run loopt. Op een
            // synchrone wachtrij zet dit een run één slag verder; op een
            // echte wachtrij is het het vangnet voor een keten die stilviel.
            $schedule->command('dashed:media-conversions tick')
                ->everyMinute()
                ->withoutOverlapping()
                ->when(fn () => MediaLibrary::usesOwnEngine() && app(\Dashed\DashedFiles\Classes\Conversions\ConversionPump::class)->hasActiveRun());

            // Eén keer per maand de uitsneden van oude focuspunten opruimen
            // die voorbij de bewaartermijn zijn. Goedkoop: alleen de rijen
            // waar iets genoteerd staat, geen listing van de schijf.
            $schedule->command('dashed:media-conversions cleanup --force')
                ->monthlyOn(1 + ($spreadMinute('media-conversions-cleanup-day', 28, 0) % 28), '05:' . str_pad((string) $spreadMinute('media-conversions-cleanup', 55, 10), 2, '0', STR_PAD_LEFT))
                ->withoutOverlapping()
                ->when(fn () => MediaLibrary::usesOwnEngine());
            // MediaHelper::getSingleMedia() vult een ontbrekende url op aanvraag; dit is alleen voorwerk.
            $schedule->command(CreateConversionUrls::class)->dailyAt('04:30')->withoutOverlapping()
                ->when(fn () => ! MediaLibrary::usesOwnEngine());
            // De nachtelijke herberekening van "waar gebruikt": vangt op wat
            // zonder model-events is geschreven. Via de wachtrij, per model.
            // Elke nacht alleen wat in de laatste twee dagen is gewijzigd:
            // OrderLog en ChatMessage hebben op een webshop miljoenen rijen.
            // Eén keer per week alles, op een dag en minuut die per site
            // verschillen, zodat niet elke site op dezelfde server tegelijk begint.
            $spread = fn (string $task, int $window, int $fallback) => class_exists(\Dashed\DashedCore\Classes\ScheduleSpread::class)
                ? \Dashed\DashedCore\Classes\ScheduleSpread::minute($task, $window)
                : $fallback;
            $minute = str_pad((string) $spread('media-usages', 55, 40), 2, '0', STR_PAD_LEFT);
            $weekday = $spread('media-usages-weekly', 7, 0) % 7;

            // Bestanden die nog van of naar de privé-schijf moeten: elke
            // minuut een slag, alleen als er iets wacht. Op een echte
            // wachtrij is dit het vangnet voor wat eerder mislukte.
            $schedule->command('dashed:media-private relocate --if-needed')
                ->everyMinute()
                ->withoutOverlapping()
                ->when(fn () => MediaLibrary::usesOwnEngine() && rescue(fn () => app(\Dashed\DashedFiles\Classes\Access\MediaVisibility::class)->needsWork(), false, false));

            $schedule->command('dashed:media-usages --queue --recent')
                ->dailyAt('02:' . $minute)
                ->skip(fn () => now()->dayOfWeek === $weekday)
                ->withoutOverlapping();
            $schedule->command('dashed:media-usages --queue')
                ->weeklyOn($weekday, '02:' . $minute)
                ->withoutOverlapping();
        });
    }

    /**
     * Een policy die een project op de oude klassenaam heeft aangemeld
     * (`Gate::policy(<oude naam>::class, ...)`) geldt na het weghalen van het
     * pakket voor ons model. De Gate zoekt een policy op de klasse van het
     * object, en dat is dan MediaItem: zonder deze stap zou zo'n policy
     * stilzwijgend niet meer gelden. Met het pakket nog aanwezig verandert
     * er niets.
     */
    public static function forwardLegacyPolicies(): void
    {
        $gate = app(\Illuminate\Contracts\Auth\Access\Gate::class);

        if (! method_exists($gate, 'policies')) {
            return;
        }

        $policies = $gate->policies();

        foreach (LegacyClassAliases::map() as $old => $ours) {
            $policy = $policies[$old] ?? null;

            if ($policy === null || isset($policies[$ours]) || LegacyClassAliases::isPackageClass($old)) {
                continue;
            }

            $gate->policy($ours, is_object($policy) ? $policy::class : $policy);
        }
    }

    /**
     * Naar welk model de morph-alias van een bibliotheekitem wijst. Spatie
     * maakt via die alias het model dat de formaten registreert. Op de eigen
     * motor is de plugin van het oude pakket niet geregistreerd, en het
     * pakketmodel vraagt die plugin op: zonder deze stap crasht elke aanvraag
     * van een formaat en wordt er geen enkel formaat meer gemaakt. Daarom
     * wijst de alias dan naar ons model, net als een mediarij die nog de
     * volle pakketklassenaam draagt.
     *
     * Op de oude motor blijft alles van het pakket. De kaart is statisch en
     * overleeft in tests de herstart: een proces dat eerder op de eigen
     * motor opstartte krijgt hier de pakketklasse terug.
     */
    public static function mapMorphAlias(): void
    {
        $relation = \Illuminate\Database\Eloquent\Relations\Relation::class;

        if (MediaLibrary::usesOwnEngine()) {
            $relation::morphMap([
                MediaItem::MORPH_ALIAS => MediaItem::class,
                MediaItem::LEGACY_CLASS => MediaItem::class,
            ]);

            return;
        }

        $map = $relation::morphMap();

        $leaked = ($map[MediaItem::MORPH_ALIAS] ?? null) === MediaItem::class || isset($map[MediaItem::LEGACY_CLASS]);

        if ($leaked && LegacyClassAliases::isPackageClass(MediaItem::LEGACY_CLASS)) {
            $map[MediaItem::MORPH_ALIAS] = MediaItem::LEGACY_CLASS;
            unset($map[MediaItem::LEGACY_CLASS]);
            $relation::morphMap($map, false);
        }
    }

    /**
     * Elke kolom en instelling die een media-id kan bevatten, voor de
     * gebruiksindex. Centraal en elk achter class_exists, zodat een site
     * zonder dat pakket niets merkt. Statisch en apart, zodat een test het
     * register kan legen en opnieuw vullen. Een klantproject meldt eigen
     * velden aan met MediaUsageRegistry::extend().
     */
    public static function registreerMediagebruik(): void
    {
        $attribute = function (string $class, string $name, string $kind = 'id', ?\Closure $when = null): void {
            if (class_exists($class)) {
                MediaUsageRegistry::attribute($class, $name, $kind, $when);
            }
        };

        $ecommerce = 'Dashed\\DashedEcommerceCore\\Models\\';
        $articles = 'Dashed\\DashedArticles\\Models\\';
        $vacancies = 'Dashed\\DashedVacancies\\Models\\';
        $core = 'Dashed\\DashedCore\\Models\\';
        $chat = 'Dashed\\DashedLivechat\\Models\\';
        $newsletter = 'Dashed\\DashedNewsletter\\Models\\';

        // Platte attributen. Alleen waarden van enkel cijfers tellen; een
        // kolom als PaymentMethod.image of OrderLog.images bevat ook paden.
        $attribute($ecommerce . 'Product', 'images', 'ids');
        $attribute($ecommerce . 'ProductGroup', 'images', 'ids');
        $attribute($ecommerce . 'ProductCategory', 'image');
        $attribute($ecommerce . 'ProductFilterOption', 'image');
        $attribute($ecommerce . 'ProductExtraOption', 'image');
        $attribute($ecommerce . 'PaymentMethod', 'image');
        $attribute($ecommerce . 'OrderLog', 'images', 'ids');
        // Foto's bij een offerteregel: staan in de PDF en op de offertepagina.
        $attribute($ecommerce . 'QuoteLine', 'images', 'ids');
        $attribute($articles . 'Article', 'image');
        $attribute($articles . 'ArticleAuthor', 'image');
        $attribute($vacancies . 'Vacancy', 'image');
        $attribute($core . 'Metadata', 'image');
        $attribute($core . 'Review', 'image');
        $attribute($core . 'Review', 'profile_image');
        $attribute($chat . 'ChatAgent', 'avatar');
        $attribute($chat . 'ChatMessage', 'attachments', 'ids');
        $attribute($newsletter . 'NewsletterList', 'mail_logo');
        $attribute('Dashed\\DashedTranslations\\Models\\Translation', 'value', 'id', fn ($translation) => $translation->type === 'image');

        // Builder-inhoud.
        foreach ([
            'Dashed\\DashedPages\\Models\\Page', $articles . 'Article', $articles . 'ArticleCategory', $articles . 'ArticleAuthor',
            $ecommerce . 'Product', $ecommerce . 'ProductGroup', $ecommerce . 'ProductCategory',
            $vacancies . 'Vacancy', $vacancies . 'VacancyCategory', $core . 'GlobalBlock',
        ] as $class) {
            $attribute($class, 'content', 'blocks');
        }

        $attribute($core . 'EmailTemplate', 'blocks', 'blocks');
        $attribute($newsletter . 'NewsletterCampaign', 'blocks', 'blocks');
        $attribute($newsletter . 'NewsletterList', 'header_blocks', 'blocks');
        $attribute($newsletter . 'NewsletterList', 'footer_blocks', 'blocks');
        $attribute($core . 'CustomBlock', 'blocks', 'custom_blocks');

        // Rijke tekst buiten blokken; die binnen blokken vindt de schema-wandeling.
        $attribute($ecommerce . 'Product', 'description', 'richtext');
        $attribute($ecommerce . 'ProductGroup', 'description', 'richtext');
        $attribute($ecommerce . 'ProductTab', 'content', 'richtext');

        foreach (['site_logo', 'site_favicon', 'default_meta_data_image', 'mail_logo', 'chat_avatar_url'] as $setting) {
            MediaUsageRegistry::setting($setting);
        }
    }

    /**
     * Bestanden en mappen aanmelden bij de prullenbak van dashed-core, zodat
     * ze daar zichtbaar en terug te zetten zijn.
     */
    public static function registreerPrullenbak(): void
    {
        $trash = 'Dashed\\DashedCore\\Classes\\Trash\\Trash';

        // Alleen op de eigen motor: het oude pakket kent de prullenbak niet.
        if (! class_exists($trash) || ! MediaLibrary::usesOwnEngine()) {
            return;
        }

        // Zonder purge: de algemene opruimer van dashed-core blijft van
        // media af. Media heeft een eigen opruimer (`media_trash`) die
        // standaard uitstaat en nooit iets weghaalt dat nog in gebruik lijkt.
        $trash::register(MediaItem::class, __('Mediabestanden'), purge: false);
        $trash::register(MediaFolder::class, __('Mediamappen'), purge: false);
    }

    /**
     * Definitief verwijderen en samenvoegen zijn niet terug te draaien en
     * vragen daarom een eigen recht. Geen migratie die het aan bestaande
     * rollen geeft: een superadmin heeft het al, de rest krijgt het bewust.
     */
    /**
     * Eén rij op de Beveiligingscheck van dashed-core: SVG-uploads. In orde
     * als er geen niet-opgeschoonde svg's zijn en de schijf niet
     * lokaal-publiek is of de header-regel aanwezig is. De closure loopt pas
     * als het scherm getekend wordt.
     */
    private static bool $securityCheckRegistered = false;

    public static function registreerBeveiligingscheck(): void
    {
        $check = 'Dashed\\DashedCore\\Security\\SecurityCheck';

        // Het register van dashed-core is statisch en overleeft de herstart
        // van de applicatie tussen twee tests: één keer per proces aanmelden.
        if (! class_exists($check) || ! method_exists($check, 'extend') || self::$securityCheckRegistered) {
            return;
        }

        self::$securityCheckRegistered = true;

        $check::extend(fn (): array => [static::svgSecurityRow()]);
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    public static function svgSecurityRow(): array
    {
        $backlog = \Dashed\DashedFiles\Classes\Svg\SvgBacklog::class;
        $count = (int) rescue(fn () => app($backlog)->countUnsanitized(), 0, false);
        $local = $backlog::diskIsLocalPublic();
        $header = $local && $backlog::headerPresent();
        // Nginx leest geen .htaccess: of de header daar gezet is valt hier niet te zien.
        $nginx = $local && stripos((string) request()->server('SERVER_SOFTWARE'), 'nginx') !== false;
        $vhost = 'location ~* \\.svgz?$ { add_header Content-Security-Policy "script-src \'none\'; sandbox" always; }';

        [$status, $detail] = match (true) {
            $count > 0 && $local && (! $header || $nginx) => ['danger', __(':count svg-bestanden zijn nooit opgeschoond en staan op een lokale publieke schijf: rechtstreeks geopend kan zo\'n bestand een script uitvoeren. Draai dashed:media-sanitize-svg.', ['count' => $count])
                . ($nginx ? ' ' . __('Deze site draait op nginx, dat .htaccess niet leest. Zet in de vhost: :regel', ['regel' => $vhost]) : '')],
            $count > 0 => ['warning', __(':count svg-bestanden zijn nooit opgeschoond. Draai dashed:media-sanitize-svg.', ['count' => $count])],
            $nginx => ['info', __('Alle svg-bestanden zijn opgeschoond. Deze site draait op nginx, dat .htaccess niet leest: of de header die scripts in een direct geopende svg uitzet er is, is hier niet te controleren. Zet in de vhost: :regel', ['regel' => $vhost])],
            $local && ! $header => ['warning', __('Alle svg-bestanden zijn opgeschoond, maar de publieke opslagmap mist de header die scripts in een direct geopende svg uitzet. Draai dashed:media-sanitize-svg.')],
            $local => ['ok', __('Alle svg-bestanden zijn opgeschoond en de publieke opslagmap stuurt voor svg een header mee die scripts uitzet.')],
            default => ['ok', __('Alle svg-bestanden zijn opgeschoond; de bestanden staan niet op een lokale publieke schijf.')],
        };

        return ['key' => 'svg_uploads', 'label' => __('SVG-uploads'), 'status' => $status, 'detail' => $detail];
    }

    public static function registreerRechten(): void
    {
        if (method_exists(cms(), 'registerRolePermissions')) {
            cms()->registerRolePermissions(__('Media'), array_merge(
                \Dashed\DashedFiles\Classes\Access\MediaRights::labels(),
                [MediaLibrary::PURGE_PERMISSION => __('Bestanden definitief verwijderen en samenvoegen')],
            ));
        }
    }

    /**
     * Twee vangnetten rond de privé-schijf.
     *
     * Een nieuw item neemt bij het aanmaken over of zijn map privé is. En
     * elke mediarij die aan een privé-item wordt gehangen gaat naar de
     * privé-schijf, wie hem ook aanmaakt en welke schijf die ook opgaf:
     * Spatie zet het bestand pas op de schijf nadat de rij is bewaard, dus
     * de schijf die hier op de rij komt is waar het bestand terechtkomt.
     */
    public static function bewaakPriveSchijf(): void
    {
        $schema = \Dashed\DashedFiles\Classes\Browse\BrowseSchema::class;
        $visibility = \Dashed\DashedFiles\Classes\Access\MediaVisibility::class;

        MediaItem::creating(function (MediaItem $item) use ($schema, $visibility) {
            if (! $schema::hasAccess() || $item->getAttribute('is_private') !== null) {
                return;
            }

            $folderId = $item->getAttribute('folder_id');

            // Vers uit de database, niet uit de onthouden boom: een lang
            // verzoek (een bulkupload) kan de boom gelezen hebben voordat een
            // ander verzoek de map op privé zette.
            if ($folderId !== null && app($visibility)->isFolderPrivateFresh((int) $folderId)) {
                $item->setAttribute('is_private', true);
            }
        });

        Media::creating(function (Media $media) use ($schema, $visibility) {
            if (! in_array((string) $media->model_type, [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS], true) || ! $schema::hasAccess()) {
                return;
            }

            $private = \Dashed\DashedFiles\Classes\Access\MediaStorage::privateDisk();

            if ($media->disk === $private && ($media->conversions_disk ?: $private) === $private) {
                return;
            }

            $row = MediaItem::withoutGlobalScopes()->toBase()->where('id', $media->model_id)->first(['is_private', 'relocate_to']);

            if ($row && (bool) $row->is_private && $row->relocate_to !== $visibility::TO_PUBLIC) {
                $media->disk = $private;
                $media->conversions_disk = $private;
            }
        });
    }

    /**
     * Het vorige bestand van een vervangen item krijgt een eigen termijn.
     * Vanuit de boot-fase en nooit vanuit register(): de labels zijn
     * vertaalaanroepen.
     */
    public static function registreerBewaartermijnen(): void
    {
        if (! class_exists(\Dashed\DashedCore\Retention\Retention::class) || ! method_exists(cms(), 'registerRetention')) {
            return;
        }

        cms()->registerRetention(
            \Dashed\DashedCore\Retention\Retention::make('media_trash')
                ->label(__('Prullenbak van de mediabibliotheek'))
                ->pakket('dashed-files', __('Mediabibliotheek'))
                // Geen tabel: een eigen opruimer, via het model. Die doet
                // alleen iets als `dashed-files.trash.auto_purge` aanstaat.
                ->opruimer(new \Dashed\DashedFiles\Retention\MediaPrullenbakOpruimer())
                ->termijn(
                    \Dashed\DashedCore\Retention\Termijn::make('media_trash', 30, 'deleted_at')
                        ->label(__('Prullenbak van de mediabibliotheek legen na (dagen)'))
                        ->uitleg(__('Geldt alleen als automatisch legen is aangezet (dashed-files.trash.auto_purge); standaard blijft een weggegooid bestand staan tot iemand het definitief verwijdert. Een bestand dat nog ergens gebruikt wordt of dat de site nog toont blijft altijd staan. Standaard: 30 dagen.'))
                )
        );

        // De runs van de pomp: een logboek dat met elke nacht en elke
        // focuswijziging een rij groeit. Een run die nog loopt blijft staan.
        cms()->registerRetention(
            \Dashed\DashedCore\Retention\Retention::make('media_conversion_runs')
                ->label(__('Runs van het genereren van afbeeldingsformaten'))
                ->pakket('dashed-files', __('Mediabibliotheek'))
                ->tabel(\Dashed\DashedFiles\Classes\Browse\BrowseSchema::RUNS)
                ->termijn(
                    \Dashed\DashedCore\Retention\Termijn::make('media_conversion_runs', 90, 'created_at')
                        ->label(__('Runs van afbeeldingsformaten bewaren (dagen)'))
                        ->uitleg(__('Het logboek van de rondes die afbeeldingsformaten genereren of opruimen. Een ronde die nog loopt blijft altijd staan. Standaard: 90 dagen.'))
                        ->filter(fn ($query) => $query->whereNotIn('status', ['pending', 'running']))
                )
        );

        // De referentie die iemand bij "Genereer met AI" uploadde en die is
        // blijven staan. Bestanden op de publieke schijf, geen tabel.
        cms()->registerRetention(
            \Dashed\DashedCore\Retention\Retention::make('media_ai_reference_temp')
                ->label(__('Tijdelijke referentieafbeeldingen voor AI'))
                ->pakket('dashed-files', __('Mediabibliotheek'))
                ->opruimer(new \Dashed\DashedFiles\Retention\AiReferentieOpruimer())
                ->termijn(
                    \Dashed\DashedCore\Retention\Termijn::make('media_ai_reference_temp', 1, 'created_at')
                        ->label(__('Tijdelijke AI-referenties bewaren (dagen)'))
                        ->uitleg(__('Een afbeelding die als referentie voor "Genereer met AI" is geüpload wordt na de aanroep meteen weggehaald. Wat toch is blijven staan gaat bij de eerstvolgende opruimronde weg zodra het ouder is dan een uur (dashed-files.source_limits.ai_reference_minutes); deze termijn is de bovengrens. Standaard: 1 dag.'))
                )
        );

        cms()->registerRetention(
            \Dashed\DashedCore\Retention\Retention::make('media_replaced')
                ->label(__('Vervangen mediabestanden'))
                ->pakket('dashed-files', __('Mediabibliotheek'))
                // Geen tabel: het zijn mediarijen in de collectie
                // `library-replaced`, opgeruimd via het model zodat de
                // bestanden meegaan (zie VervangenMediaOpruimer).
                ->opruimer(new \Dashed\DashedFiles\Retention\VervangenMediaOpruimer())
                ->termijn(
                    \Dashed\DashedCore\Retention\Termijn::make('media_replaced', 30, 'updated_at')
                        ->label(__('Vervangen bestanden bewaren (dagen)'))
                        ->uitleg(__('Na het vervangen van een bestand in de mediabibliotheek blijft het oude bestand nog staan, zodat al verstuurde mails en gedeelde links blijven werken. Standaard: 30 dagen.'))
                )
        );
    }

    /**
     * De externe bronnen van de mediabibliotheek (fase 11): een URL, AI en
     * de stockaanbieders. Lui, zodat er tijdens de boot niets vertaald of
     * uit de database gelezen wordt; of een bron er ook echt is (sleutel,
     * pakket, instelling) beslist hij zelf op het moment dat het venster
     * erom vraagt. Een ander pakket meldt zijn bron op dezelfde manier aan.
     */
    public static function registreerMediabronnen(): void
    {
        $registry = app(\Dashed\DashedFiles\Classes\Sources\MediaSourceRegistry::class);

        $registry->register(\Dashed\DashedFiles\Classes\Sources\UrlSource::KEY, fn () => new \Dashed\DashedFiles\Classes\Sources\UrlSource());
        $registry->register(\Dashed\DashedFiles\Classes\Sources\Stock\PexelsSource::KEY, fn () => new \Dashed\DashedFiles\Classes\Sources\Stock\PexelsSource());
        $registry->register(\Dashed\DashedFiles\Classes\Sources\Stock\PixabaySource::KEY, fn () => new \Dashed\DashedFiles\Classes\Sources\Stock\PixabaySource());
        $registry->register(\Dashed\DashedFiles\Classes\Sources\AiSource::KEY, fn () => new \Dashed\DashedFiles\Classes\Sources\AiSource());
    }

    /**
     * De eigen acties in de mediabibliotheek. Onder een naam, zodat een
     * tweede boot ze vervangt, en lui, zodat __() pas bij het renderen draait.
     *
     * Elke taak is een betaalde AI-aanroep, en "alles in dit filter
     * selecteren" levert tot duizenden items op. Daarom in bulk: alleen wat
     * er echt iets aan heeft, een plafond per aanroep, en vooraf een
     * bevestiging met het echte aantal en de talen erin.
     */
    public static function registerMediaActions(): void
    {
        // Als tekst: dashed-ai is geen vereiste van dit pakket.
        $altJob = 'Dashed\\DashedAi\\Jobs\\GenerateMediaAltText';
        $tagJob = 'Dashed\\DashedAi\\Jobs\\GenerateMediaTags';
        $max = self::ALT_TEXT_BULK_MAX;

        // Alleen wat een vision-provider aanneemt; een svg of gif levert niets op.
        // En nooit een bijlage van een bezoeker of een bestelling: die gaat niet naar een externe dienst.
        $canSee = fn (MediaItem $item) => in_array((string) ($item->mime_type ?? $item->file()?->mime_type), ['image/jpeg', 'image/png', 'image/webp'], true)
            && ! \Dashed\DashedFiles\Classes\MediaPrivacy::isProtected($item);
        // Een afbeelding waar de AI drie keer niets van maakte doet in bulk niet meer mee: de taak zou
        // hem overslaan, en dan klopt "wordt gegenereerd" niet. Hij staat zichtbaar op de AI-instellingen
        // en in het detailpaneel, met "Opnieuw proberen".
        $needsAlt = fn (MediaItem $item) => $canSee($item) && $item->missingAltLocales() !== [] && (int) $item->automatic_alt_tries < self::ALT_TEXT_MAX_TRIES;
        $locales = fn (array $ids) => implode(', ', array_map('strtoupper', $ids));
        $capNote = fn (int $count, int $now) => $count > $now ? ' ' . __('Per keer hooguit :max; :skipped blijven over voor een volgende keer.', ['max' => $max, 'skipped' => $count - $now]) : '';
        $skipNote = fn (int $count, int $now) => $count > $now ? ' ' . __(':skipped overgeslagen: per keer hooguit :max.', ['skipped' => $count - $now, 'max' => $max]) : '';

        $entry = fn (string $key, string $label, \Closure $handle, ?\Closure $confirm = null) => [array_filter([
            'key' => $key,
            'label' => $label,
            'icon' => 'heroicon-o-sparkles',
            'visible' => $canSee,
            'confirm' => $confirm,
            'handle' => $handle,
        ])];

        // ------------------------------------------------------------------
        // Eén bestand
        // ------------------------------------------------------------------
        MediaActionRegistry::register(MediaActionRegistry::ITEM, fn () => class_exists($altJob) ? $entry(
            'generate-alt-text',
            __('Alt-tekst genereren'),
            function (MediaItem $item) use ($altJob, $locales) {
                $missing = $item->missingAltLocales();

                if ($missing === []) {
                    // Bevestigd overschrijven, in elke taal.
                    dispatch(new $altJob((int) $item->getKey(), null, true));

                    return __('De alt-tekst wordt opnieuw gegenereerd. Dat kan even duren.');
                }

                // Een mens die het voor dit ene bestand nog eens vraagt krijgt
                // een nieuwe poging, ook na drie mislukte.
                if ((int) $item->automatic_alt_tries >= self::ALT_TEXT_MAX_TRIES) {
                    app(MediaLibrary::class)->resetAltTries([(int) $item->getKey()]);
                }

                dispatch(new $altJob((int) $item->getKey()));

                return \Dashed\DashedFiles\Classes\MediaTexts::isMultilingual()
                    ? __('De alt-tekst wordt gegenereerd voor: :locales. Dat kan even duren.', ['locales' => $locales($missing)])
                    : __('De alt-tekst wordt gegenereerd. Dat kan even duren.');
            },
            fn (MediaItem $item) => $item->missingAltLocales() === []
                ? __('Dit bestand heeft al een alt-tekst in elke taal. Opnieuw genereren overschrijft die, en is een betaalde AI-aanroep.')
                : null,
        ) : [], 'dashed-files.alt-text');

        MediaActionRegistry::register(MediaActionRegistry::ITEM, fn () => class_exists($tagJob) ? $entry(
            'suggest-tags',
            __('Tags voorstellen'),
            function (MediaItem $item) use ($tagJob) {
                dispatch(new $tagJob((int) $item->getKey()));

                return __('Er worden tags voorgesteld en toegevoegd. Dat kan even duren.');
            },
        ) : [], 'dashed-files.tags');

        // ------------------------------------------------------------------
        // Een selectie
        // ------------------------------------------------------------------
        $bulk = function (string $key, string $label, \Closure $eligible, \Closure $dispatch, \Closure $confirmText, \Closure $doneText, string $noneText, \Closure $restText) use ($entry, $max, $capNote, $skipNote) {
            return $entry(
                $key,
                $label,
                function (\Illuminate\Support\Collection $items) use ($eligible, $dispatch, $doneText, $restText, $max, $skipNote) {
                    $fit = $items->filter($eligible)->values();
                    $now = $fit->take($max);

                    $now->each($dispatch);

                    return $doneText($now->count())
                        . $skipNote($fit->count(), $now->count())
                        . ($items->count() > $fit->count() ? ' ' . $restText($items->count() - $fit->count()) : '');
                },
                function (\Illuminate\Support\Collection $items) use ($eligible, $confirmText, $noneText, $max, $capNote) {
                    $count = $items->filter($eligible)->count();
                    $now = min($count, $max);

                    return $now === 0 ? $noneText : $confirmText($now) . $capNote($count, $now);
                },
            );
        };

        $languages = function () use ($locales) {
            $ids = \Dashed\DashedFiles\Classes\MediaTexts::locales();

            return ['multiple' => count($ids) > 1, 'count' => count($ids), 'list' => $locales($ids)];
        };

        MediaActionRegistry::register(MediaActionRegistry::BULK, fn () => class_exists($altJob) ? $bulk(
            'generate-alt-text',
            __('Alt-tekst genereren'),
            $needsAlt,
            fn (MediaItem $item) => dispatch(new $altJob((int) $item->getKey())),
            fn (int $now) => ($in = $languages())['multiple']
                ? __('Voor :count afbeeldingen wordt de alt-tekst aangevuld waar hij ontbreekt, in :locales talen (:list). Elke afbeelding is één betaalde AI-aanroep, voor alle talen tegelijk.', ['count' => $now, 'locales' => $in['count'], 'list' => $in['list']])
                : __('Voor :count afbeeldingen zonder alt-tekst wordt een alt-tekst gegenereerd. Elke afbeelding is een betaalde AI-aanroep.', ['count' => $now]),
            fn (int $now) => __('Voor :count afbeeldingen wordt een alt-tekst gegenereerd.', ['count' => $now]),
            __('Geen van de geselecteerde bestanden is een afbeelding zonder alt-tekst.'),
            fn (int $count) => __(':count hadden al een alt-tekst of zijn geen afbeelding.', ['count' => $count]),
        ) : [], 'dashed-files.alt-text');

        MediaActionRegistry::register(MediaActionRegistry::BULK, fn () => class_exists($tagJob) ? $bulk(
            'suggest-tags',
            __('Tags voorstellen'),
            $canSee,
            fn (MediaItem $item) => dispatch(new $tagJob((int) $item->getKey())),
            fn (int $now) => __('Voor :count afbeeldingen worden tags voorgesteld en toegevoegd. Elke afbeelding is een betaalde AI-aanroep.', ['count' => $now]),
            fn (int $now) => __('Voor :count afbeeldingen worden tags voorgesteld.', ['count' => $now]),
            __('Geen van de geselecteerde bestanden is een afbeelding waar de AI tags voor kan voorstellen.'),
            fn (int $count) => __(':count zijn geen afbeelding (jpg, png of webp).', ['count' => $count]),
        ) : [], 'dashed-files.tags');

        // Beide in één aanroep per afbeelding: half zo duur als de twee acties na elkaar.
        MediaActionRegistry::register(MediaActionRegistry::BULK, fn () => class_exists($altJob) && class_exists($tagJob) ? $bulk(
            'generate-alt-text-and-tags',
            __('Alt-tekst en tags genereren'),
            $canSee,
            fn (MediaItem $item) => dispatch(new $altJob((int) $item->getKey(), null, false, true)),
            fn (int $now) => ($in = $languages())['multiple']
                ? __('Voor :count afbeeldingen worden tags voorgesteld en wordt de alt-tekst aangevuld waar hij ontbreekt, in :locales talen (:list). Elke afbeelding is één betaalde AI-aanroep voor beide.', ['count' => $now, 'locales' => $in['count'], 'list' => $in['list']])
                : __('Voor :count afbeeldingen worden tags voorgesteld en wordt een ontbrekende alt-tekst gegenereerd. Elke afbeelding is één betaalde AI-aanroep voor beide.', ['count' => $now]),
            fn (int $now) => __('Voor :count afbeeldingen worden alt-tekst en tags gegenereerd.', ['count' => $now]),
            __('Geen van de geselecteerde bestanden is een afbeelding waar de AI iets mee kan.'),
            fn (int $count) => __(':count zijn geen afbeelding (jpg, png of webp).', ['count' => $count]),
        ) : [], 'dashed-files.alt-text-and-tags');
    }

    public static function flushBetweenJobs(): void
    {
        MediaPickerFileCache::flush();

        app(MediaLibrary::class)->flushRuntime();

        // Een werker leeft uren: de motor en de AVIF-instelling staan op het
        // verzoek onthouden, en dat verzoek is in een werker altijd hetzelfde.
        MediaLibrary::forgetEngine();
        MediaLibrary::forgetAvif();
        // Ook wat er per verzoek onthouden is over het schema, de spiegeltaal
        // en de beschermde mappen: in een werker is dat verzoek altijd hetzelfde.
        \Dashed\DashedFiles\Classes\Browse\BrowseSchema::forgetMissing();
        \Dashed\DashedFiles\Classes\MediaTexts::forgetMirror();
        \Dashed\DashedFiles\Classes\MediaPrivacy::forget();
        \Dashed\DashedFiles\Classes\Access\MediaVisibility::forget();

        // Wat een taak aan ontbrekende formaten tegenkwam gaat hier naar de
        // pomp: in een werker is er geen einde van een verzoek.
        rescue(fn () => app(\Dashed\DashedFiles\Classes\Conversions\ConversionPump::class)->flushRequested(), report: false);
    }

    /**
     * Na het samenvoegen van de configuratie: de schakelaar van de aliassen
     * voor de oude klassenamen. Al vóór de boot, want een provider die later
     * boot kan de oude naam al noemen.
     */
    public function packageRegistered(): void
    {
        LegacyClassAliases::register();
        LegacyClassAliases::$enabled = (bool) config('dashed-files.legacy_aliases', true);
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
                ClearTempImages::class,
                ClearMediaConversions::class,
                MediaDoctorCommand::class,
                \Dashed\DashedFiles\Commands\MediaEngineCommand::class,
                \Dashed\DashedFiles\Commands\MediaChecksumsCommand::class,
                \Dashed\DashedFiles\Commands\MediaTextsCommand::class,
                \Dashed\DashedFiles\Commands\MediaMirrorCommand::class,
                \Dashed\DashedFiles\Commands\MediaUsagesCommand::class,
                \Dashed\DashedFiles\Commands\BackfillMediaDimensionsCommand::class,
                \Dashed\DashedFiles\Commands\MediaConversionsCommand::class,
                \Dashed\DashedFiles\Commands\MediaPreviewsCommand::class,
                \Dashed\DashedFiles\Commands\MediaSanitizeSvgCommand::class,
                \Dashed\DashedFiles\Commands\MediaPrivateCommand::class,
                \Dashed\DashedFiles\Commands\RemoveLegacyMediaLibraryCommand::class,
            ])
            ->hasConfigFile([
                'media-library',
                'dashed-files',
            ]);
    }
}
