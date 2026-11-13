<?php

namespace Dashed\DashedFiles\Filament\Pages\Settings;

use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Classes\Access\MediaSites;
use Dashed\DashedFiles\Classes\MediaCapabilities;
use Dashed\DashedCore\Traits\HasSettingsPermission;
use Dashed\DashedFiles\Classes\Conversions\ConversionPump;
use Dashed\DashedFiles\Classes\Sources\MediaSourceSettings;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;

/**
 * Instellingen van de eigen mediabibliotheek: AVIF, het automatisch legen
 * van de prullenbak, en de knop die ontbrekende formaten laat genereren.
 * Beide schakelaars gelden voor de hele installatie (de eerste site): er is
 * één bibliotheek, niet één per site.
 *
 * De externe bronnen (fase 11) zijn wel per site: elke site vult zijn eigen
 * sleutel van een stockaanbieder in. Een sleutel wordt versleuteld bewaard
 * en nooit teruggestuurd naar de browser: het veld is leeg, en leeg laten
 * houdt wat er stond.
 */
class MediaSettingsPage extends Page
{
    use \Dashed\DashedCore\Filament\Concerns\TranslatesPageLabels;
    use HasSettingsPermission {
        canAccess as protected canAccessSettings;
    }

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Media';

    // Niet onder `media-...`: daar staat de bibliotheek zelf, en een test
    // bewaakt dat er onder die naam precies één pagina is.
    protected static ?string $slug = 'instellingen-mediabibliotheek';

    protected string $view = 'dashed-core::settings.pages.default-settings';

    public array $data = [];

    // Alleen op de eigen motor: het oude pakket kent geen van deze dingen.
    public static function canAccess(): bool
    {
        return MediaLibrary::usesOwnEngine() && static::canAccessSettings();
    }

    public function mount(): void
    {
        $this->form->fill([
            'media_avif_enabled' => MediaLibrary::avifEnabled(),
            'media_trash_auto_purge' => MediaLibrary::trashAutoPurge(),
            'sources' => array_combine(array_map([static::class, 'siteStateKey'], static::sourceSiteIds()), array_map(fn (string $siteId) => [
                'url_enabled' => MediaSourceSettings::enabled('url', $siteId),
                'pexels_enabled' => MediaSourceSettings::enabled('pexels', $siteId),
                'pixabay_enabled' => MediaSourceSettings::enabled('pixabay', $siteId),
                // De sleutels zelf gaan nooit naar de browser.
                'pexels_key' => null,
                'pixabay_key' => null,
                'pexels_clear' => false,
                'pixabay_clear' => false,
                'stock_max_width' => MediaSourceSettings::stockMaxWidth($siteId),
                'ai_enabled' => MediaSourceSettings::enabled('ai', $siteId),
                'ai_daily_limit' => MediaSourceSettings::aiDailyLimit($siteId),
                'ai_edit_daily_limit' => MediaSourceSettings::aiEditDailyLimit($siteId),
            ], static::sourceSiteIds())),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $driver = MediaCapabilities::imageDriver();
        $capable = MediaCapabilities::avif();

        return $schema->schema([
            Section::make(__('Afbeeldingsformaten'))
                ->schema([
                    Toggle::make('media_avif_enabled')
                        ->label(__('AVIF-varianten aanbieden'))
                        ->helperText($capable
                            ? __('Deze server kan AVIF schrijven (beelddriver: :driver). AVIF wordt alleen op de achtergrond gemaakt, nooit bij het uploaden: na het aanzetten maakt de nachtelijke ronde de varianten van de formaten die de site uitlevert. Een browser die AVIF niet kent krijgt gewoon WebP.', ['driver' => $driver])
                            : __('Deze server kan geen AVIF schrijven (beelddriver: :driver). De schakelaar doet hier niets tot de beelddriver AVIF ondersteunt.', ['driver' => $driver])),
                ]),
            Section::make(__('Prullenbak'))
                ->schema([
                    Toggle::make('media_trash_auto_purge')
                        ->label(__('Prullenbak automatisch legen na de bewaartermijn'))
                        ->helperText(__('Uit (de standaard): een weggegooid bestand blijft staan tot iemand het definitief verwijdert. Aan: na de bewaartermijn bij Instellingen, Opschonen gaat het vanzelf weg, behalve als het nog ergens gebruikt wordt of de site het nog toont. De lijst "waar gebruikt" kent alleen de aangemelde velden; zet dit pas aan nadat dashed:media-usages --audit niets meer meldt.')),
                ]),
            ...$this->sourceSections(),
        ])->statePath('data');
    }

    /**
     * @return array<int, string>
     */
    protected static function sourceSiteIds(): array
    {
        return array_map('strval', array_keys(MediaSites::all()));
    }

    /**
     * De sleutel van een site in de staat van het formulier: het site-id
     * zelf, zodat een gewijzigde sitelijst tussen laden en opslaan niets
     * verschuift. Een id met tekens die een statepath breken krijgt een
     * vaste afgeleide sleutel.
     */
    public static function siteStateKey(string $siteId): string
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $siteId) && ! ctype_digit($siteId) ? $siteId : 'site_' . substr(hash('sha256', $siteId), 0, 16);
    }

    /**
     * Per site een sectie met de externe bronnen. De velden van een
     * aanbieder die uitstaat zijn verborgen en houden hun waarde.
     *
     * @return array<int, Section>
     */
    protected function sourceSections(): array
    {
        $sites = MediaSites::all();
        $sections = [];

        foreach (array_keys($sites) as $siteId) {
            $siteId = (string) $siteId;
            $stateKey = static::siteStateKey($siteId);
            $path = fn (string $name) => "sources.{$stateKey}.{$name}";

            $stock = function (string $key, string $name, string $where) use ($path, $siteId): array {
                $has = MediaSourceSettings::hasKey($key, $siteId);

                return [
                    Toggle::make($path("{$key}_enabled"))
                        ->label(__(':provider aanbieden in de mediabibliotheek', ['provider' => $name]))
                        ->helperText(__('Standaard uit. Aan betekent dat beheerders met het recht om te uploaden bij :provider kunnen zoeken en een foto naar de eigen bibliotheek kopiëren.', ['provider' => $name]))
                        ->live(),
                    TextInput::make($path("{$key}_key"))
                        ->label(__('Sleutel van :provider', ['provider' => $name]))
                        ->password()
                        ->autocomplete('new-password')
                        ->maxLength(200)
                        ->placeholder($has ? __('Er is een sleutel ingesteld') : __('Nog geen sleutel'))
                        ->helperText($has
                            ? __('De sleutel wordt versleuteld bewaard en hier nooit getoond. Laat leeg om hem te houden, of vul een nieuwe in om hem te vervangen. Aanvragen: :where.', ['where' => $where])
                            : __('Elke site gebruikt zijn eigen sleutel; de limieten van :provider tellen per sleutel. Aanvragen: :where.', ['provider' => $name, 'where' => $where]))
                        ->visible(fn (callable $get) => (bool) $get($path("{$key}_enabled")))
                        ->dehydratedWhenHidden(),
                    Toggle::make($path("{$key}_clear"))
                        ->label(__('Sleutel van :provider verwijderen', ['provider' => $name]))
                        ->visible(fn (callable $get) => $has && (bool) $get($path("{$key}_enabled")))
                        ->dehydratedWhenHidden(),
                ];
            };

            $sections[] = Section::make(count($sites) > 1 ? __('Externe bronnen: :site', ['site' => $sites[$siteId] ?? $siteId]) : __('Externe bronnen'))
                ->description(__('Tabbladen in het keuzevenster en knoppen in de mediabibliotheek waarmee een afbeelding van buiten naar de eigen bibliotheek wordt gekopieerd. Een bron zonder sleutel of die uitstaat wordt niet getoond.'))
                ->schema([
                    Toggle::make($path('url_enabled'))
                        ->label(__('Afbeelding van een URL importeren'))
                        ->helperText(__('Alleen https-adressen buiten het eigen netwerk, alleen afbeeldingen, tot 25 MB.')),
                    ...$stock('pexels', 'Pexels', 'pexels.com/api'),
                    ...$stock('pixabay', 'Pixabay', 'pixabay.com/api/docs'),
                    TextInput::make($path('stock_max_width'))
                        ->label(__('Grootste breedte van een geïmporteerde stockfoto (pixels)'))
                        ->numeric()
                        ->minValue(800)
                        ->maxValue(6000)
                        ->helperText(__('Standaard 2500. Het origineel van een stockfoto is vaak tientallen megapixels; dat is voor een website nooit nodig. Pixabay levert zonder aparte goedkeuring hooguit 1280 pixels.'))
                        ->visible(fn (callable $get) => (bool) $get($path('pexels_enabled')) || (bool) $get($path('pixabay_enabled')))
                        ->dehydratedWhenHidden(),
                    Toggle::make($path('ai_enabled'))
                        ->label(__('Afbeeldingen maken met AI'))
                        ->helperText(__('Werkt alleen als er bij Instellingen, AI een Fal.ai-sleutel staat. Elke afbeelding kost tegoed bij die dienst.'))
                        ->live(),
                    TextInput::make($path('ai_daily_limit'))
                        ->label(__('Hoogste aantal AI-afbeeldingen per dag voor deze site'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1000)
                        ->helperText(__('Standaard 25. Telt elke betaalde aanvraag om een nieuwe afbeelding te maken, via welke knop ook: het keuzevenster, de AI-knop op een veld en de beelden die de inhoudsstudio laat maken. Ook een aanvraag die mislukt telt mee. Is het plafond bereikt, dan kan er die dag niets meer gegenereerd worden. 0 = geen.'))
                        ->visible(fn (callable $get) => (bool) $get($path('ai_enabled')))
                        ->dehydratedWhenHidden(),
                    TextInput::make($path('ai_edit_daily_limit'))
                        ->label(__('Hoogste aantal AI-bewerkingen per dag voor deze site'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(5000)
                        ->helperText(__('Standaard 100. Telt elke betaalde aanroep van de AI-bewerkingen in de bibliotheek: upscalen, achtergrond verwijderen, retoucheren en productfoto (die laatste telt twee keer: uitknippen en de studio). 0 = geen bewerkingen.')),
                ]);
        }

        return $sections;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateMissing')
                ->label(__('Ontbrekende formaten genereren'))
                ->icon('heroicon-o-photo')
                ->requiresConfirmation()
                ->modalDescription(__('Alle formaten die een afbeelding hoort te hebben maar die er niet zijn worden op de achtergrond gemaakt, in kleine stappen. De voortgang staat in de mediabibliotheek.'))
                ->action(function () {
                    try {
                        $run = app(MediaLibrary::class)->startConversionRun(ConversionPump::MISSING, [], is_numeric(auth()->id()) ? (int) auth()->id() : null);
                    } catch (MediaRejectedException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('De formaten van :count afbeeldingen worden nagelopen.', ['count' => $run->total]))->success()->send();
                }),
        ];
    }

    public function submit(): void
    {
        $state = $this->form->getState();

        MediaLibrary::setAvifEnabled((bool) ($state['media_avif_enabled'] ?? false));
        MediaLibrary::setTrashAutoPurge((bool) ($state['media_trash_auto_purge'] ?? false));

        // Op site-id, niet op volgorde: alleen sites die nu bestaan, en wat
        // voor een site in de staat staat hoort ook echt bij die site.
        foreach (static::sourceSiteIds() as $siteId) {
            $source = is_array($state['sources'][static::siteStateKey($siteId)] ?? null) ? $state['sources'][static::siteStateKey($siteId)] : null;

            if ($source === null) {
                continue;
            }

            MediaSourceSettings::setEnabled('url', (bool) ($source['url_enabled'] ?? false), $siteId);
            MediaSourceSettings::setEnabled('ai', (bool) ($source['ai_enabled'] ?? false), $siteId);

            foreach (['pexels', 'pixabay'] as $provider) {
                MediaSourceSettings::setEnabled($provider, (bool) ($source["{$provider}_enabled"] ?? false), $siteId);

                $key = trim((string) ($source["{$provider}_key"] ?? ''));

                if ($key !== '') {
                    MediaSourceSettings::setKey($provider, $key, $siteId);
                } elseif ($source["{$provider}_clear"] ?? false) {
                    MediaSourceSettings::setKey($provider, null, $siteId);
                }
            }

            if (is_numeric($source['stock_max_width'] ?? null)) {
                MediaSourceSettings::setStockMaxWidth((int) $source['stock_max_width'], $siteId);
            }

            if (is_numeric($source['ai_daily_limit'] ?? null)) {
                MediaSourceSettings::setAiDailyLimit((int) $source['ai_daily_limit'], $siteId);
            }

            if (is_numeric($source['ai_edit_daily_limit'] ?? null)) {
                MediaSourceSettings::setAiEditDailyLimit((int) $source['ai_edit_daily_limit'], $siteId);
            }
        }

        // De sleutelvelden weer leeg: wat net is getypt blijft niet in de staat van de pagina staan.
        $this->mount();

        Notification::make()->title(__('Instellingen opgeslagen'))->success()->send();
    }
}
