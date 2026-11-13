<?php

namespace Dashed\DashedFiles\Filament\Actions;

use Filament\Actions\Action;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\Storage;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Component;
use Dashed\DashedFiles\Services\AiImageGenerator;
use Illuminate\Support\Facades\Schema as DbSchema;
use Dashed\DashedFiles\Services\SubjectImageResolver;

class AiGenerateImageAction
{
    /** De map op de publieke schijf waar een geüploade referentie even staat. */
    public const REFERENCE_DIRECTORY = 'ai-reference-temp';

    public const REFERENCE_DISK = 'public';

    /**
     * De knop op een veld van de eigen mediabibliotheek: zelfde naam en
     * uiterlijk als make(), maar hij opent alleen het keuzevenster op de
     * bron `ai`. Daar zit het formulier, en het genereren gaat via de wachtrij.
     */
    public static function opener(): Action
    {
        return Action::make('aiGenerateImage')
            ->label(__('Genereer met AI'))
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->alpineClickHandler("window.dashedMedia && window.dashedMedia.field && window.dashedMedia.field.pickSource(\$el, 'ai')");
    }

    /**
     * Of de bron `ai` voor dit veld bestaat: sleutel en AI-pakket aanwezig,
     * het veld sluit hem niet uit, en de gebruiker mag uploaden.
     */
    public static function sourceAvailableFor(mixed $field = null): bool
    {
        return (bool) rescue(function () use ($field) {
            $only = is_object($field) && method_exists($field, 'getSources') ? $field->getSources() : null;
            $registry = app(\Dashed\DashedFiles\Classes\Sources\MediaSourceRegistry::class);

            return \Dashed\DashedFiles\Classes\MediaLibrary::allows('upload')
                && $registry->find(\Dashed\DashedFiles\Classes\Sources\AiSource::KEY, $registry::siteId(), $only) !== null;
        }, false, false);
    }

    /**
     * Haalt een geüploade referentie weer van de publieke schijf. Alleen
     * een pad in de eigen tijdelijke map; een adres of iets anders blijft staan.
     */
    public static function forgetReferenceUpload(mixed $value): void
    {
        foreach (is_array($value) ? $value : [$value] as $path) {
            if (! is_string($path) || ! str_starts_with($path, self::REFERENCE_DIRECTORY . '/') || str_contains($path, '..')) {
                continue;
            }

            rescue(fn () => Storage::disk(self::REFERENCE_DISK)->delete($path), null, false);
        }
    }

    /**
     * Build a Filament hint-action that opens a modal to generate an image via fal.ai
     * and set the resulting media library id onto the field it was attached to.
     */
    public static function make(): Action
    {
        return Action::make('aiGenerateImage')
            ->label(__('Genereer met AI'))
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->modalHeading(__('Afbeelding genereren met AI'))
            ->modalDescription(__('Beschrijf wat je wilt zien. Geef optioneel een referentieafbeelding mee voor 1-op-1 productbehoud (nano-banana/edit).'))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel(__('Genereer'))
            ->schema(self::buildSchema())
            ->action(function (array $data, $component) {
                // Wat er ook gebeurt (geen prompt, een fout bij het adres van
                // de referentie, een mislukte aanroep): de geüploade
                // referentie stond alleen voor deze ene aanroep op de publieke schijf.
                try {
                    $prompt = trim((string) ($data['prompt'] ?? ''));
                    if (! $prompt) {
                        Notification::make()
                            ->title(__('Geen prompt opgegeven'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $reference = self::resolveReferenceUrl($data);
                    $ratio = $data['ratio'] ?? '1:1';

                    // Een geplakt adres of een gekozen beeld van een onderwerp
                    // komt uit de formulierstaat: opnieuw getoetst, want een
                    // beschermd bestand van de bibliotheek gaat niet naar Fal.
                    if ($reference && \Dashed\DashedFiles\Classes\MediaPrivacy::isProtectedUrl($reference)) {
                        Notification::make()
                            ->title(__('Niet verstuurd'))
                            ->body(__('Deze referentieafbeelding is privé of beschermd en gaat niet naar de AI-dienst.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    // Via generate() en dus onder het dagplafond van de site (AiSpend).
                    $mediaId = app(AiImageGenerator::class)->generate(
                        prompt: $prompt,
                        ratio: $ratio,
                        referenceImageUrl: $reference,
                    );
                } finally {
                    self::forgetReferenceUpload($data['reference_upload'] ?? null);
                }

                if (! $mediaId) {
                    Notification::make()
                        ->title(__('Genereren mislukt'))
                        ->body(__('Check de logs of je fal.ai sleutel, en of het dagplafond voor AI-afbeeldingen (Instellingen, Media) niet bereikt is. Probeer het nog eens of pas de prompt aan.'))
                        ->danger()
                        ->send();

                    return;
                }

                $current = $component->getState();

                if (is_array($current)) {
                    $current[] = $mediaId;
                    $component->state(array_values($current));
                } else {
                    $component->state($mediaId);
                }

                Notification::make()
                    ->title(__('Afbeelding gegenereerd'))
                    ->body(__('Het resultaat is opgeslagen in de media library en gekoppeld aan dit veld.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<int, Component>
     */
    private static function buildSchema(): array
    {
        return [
            Textarea::make('prompt')
                ->label(__('Prompt'))
                ->placeholder(__('Bijv. Een minimalistische productfoto van een leren tas op een marmeren tafel, zacht daglicht, zachte schaduwen, beige achtergrond.'))
                ->rows(4)
                ->required(),

            Select::make('ratio')
                ->label(__('Beeldverhouding'))
                ->options([
                    '1:1' => __('1:1 (vierkant)'),
                    '4:5' => __('4:5 (portret, Instagram feed)'),
                    '9:16' => __('9:16 (stories/reels)'),
                    '2:3' => __('2:3 (portret)'),
                    '3:4' => __('3:4 (portret)'),
                    '4:3' => __('4:3 (landschap)'),
                    '16:9' => __('16:9 (landschap)'),
                ])
                ->default('1:1')
                ->required(),

            Radio::make('reference_source')
                ->label(__('Referentieafbeelding (optioneel)'))
                ->helperText(__('Met referentie wordt het product 1-op-1 behouden via nano-banana/edit.'))
                ->options([
                    'none' => __('Geen - tekst-naar-beeld (flux/dev)'),
                    'url' => __('URL invoeren'),
                    'upload' => __('Uploaden'),
                    'model' => __('Kies uit onderwerp in de CMS'),
                ])
                ->default('none')
                ->live(),

            TextInput::make('reference_url')
                ->label(__('Referentieafbeelding URL'))
                ->url()
                ->visible(fn (callable $get) => $get('reference_source') === 'url'),

            FileUpload::make('reference_upload')
                ->label(__('Upload referentieafbeelding'))
                ->image()
                ->disk(self::REFERENCE_DISK)
                ->directory(self::REFERENCE_DIRECTORY)
                ->visibility('public')
                ->visible(fn (callable $get) => $get('reference_source') === 'upload'),

            Select::make('reference_model_type')
                ->label(__('Onderwerp type'))
                ->options(self::routeModelOptions())
                ->nullable()
                ->live()
                ->afterStateUpdated(function (callable $set) {
                    $set('reference_model_id', null);
                    $set('reference_model_image', null);
                })
                ->visible(fn (callable $get) => $get('reference_source') === 'model'),

            Select::make('reference_model_id')
                ->label(__('Specifiek onderwerp'))
                ->searchable()
                ->nullable()
                ->live()
                ->getSearchResultsUsing(function (string $search, callable $get) {
                    $class = $get('reference_model_type');
                    if (! $class || ! array_key_exists($class, self::routeModelOptions())) {
                        return [];
                    }
                    $model = new $class();

                    return $class::query()
                        ->where(function ($q) use ($search, $model) {
                            foreach (['name', 'title'] as $col) {
                                if (DbSchema::hasColumn($model->getTable(), $col)) {
                                    $q->orWhere($col, 'like', "%{$search}%");
                                }
                            }
                        })
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn ($m) => [$m->getKey() => $m->name ?? $m->title ?? "#{$m->getKey()}"])
                        ->toArray();
                })
                ->getOptionLabelUsing(function ($value, callable $get) {
                    $class = $get('reference_model_type');
                    if (! $value || ! $class || ! array_key_exists($class, self::routeModelOptions())) {
                        return null;
                    }
                    $item = $class::find($value);

                    return $item ? ($item->name ?? $item->title ?? "#{$item->getKey()}") : null;
                })
                ->afterStateUpdated(fn (callable $set) => $set('reference_model_image', null))
                ->visible(fn (callable $get) => $get('reference_source') === 'model' && (bool) $get('reference_model_type')),

            Select::make('reference_model_image')
                ->label(__('Kies afbeelding van onderwerp'))
                ->options(function (callable $get) {
                    $class = $get('reference_model_type');
                    $id = $get('reference_model_id');
                    if (! $class || ! $id || ! array_key_exists($class, self::routeModelOptions())) {
                        return [];
                    }
                    $subject = $class::find($id);
                    if (! $subject) {
                        return [];
                    }

                    $urls = array_keys(app(SubjectImageResolver::class)->collect($subject));
                    $options = [];
                    foreach ($urls as $url) {
                        $safe = e($url);
                        $options[$url] = '<div style="display:flex;align-items:center;gap:.75rem;">'
                            .'<img src="'.$safe.'" style="width:48px;height:48px;object-fit:cover;border-radius:6px;flex-shrink:0;" />'
                            .'<span style="font-size:.75rem;opacity:.7;word-break:break-all;">'.$safe.'</span>'
                            .'</div>';
                    }

                    return $options;
                })
                ->allowHtml()
                ->native(false)
                ->visible(fn (callable $get) => $get('reference_source') === 'model' && (bool) $get('reference_model_id')),

            Placeholder::make('reference_preview')
                ->label(__('Referentie voorbeeld'))
                ->content(function (callable $get) {
                    $url = self::resolveReferenceUrl([
                        'reference_source' => $get('reference_source'),
                        'reference_url' => $get('reference_url'),
                        'reference_upload' => $get('reference_upload'),
                        'reference_model_image' => $get('reference_model_image'),
                    ]);

                    return $url
                        ? new HtmlString('<img src="'.e($url).'" style="max-height:180px;border-radius:8px;" />')
                        : '-';
                })
                ->visible(fn (callable $get) => $get('reference_source') !== 'none'),
        ];
    }

    private static function resolveReferenceUrl(array $data): ?string
    {
        $source = $data['reference_source'] ?? 'none';

        return match ($source) {
            'url' => $data['reference_url'] ?? null,
            'upload' => self::uploadToPublicUrl($data['reference_upload'] ?? null),
            'model' => $data['reference_model_image'] ?? null,
            default => null,
        };
    }

    private static function uploadToPublicUrl(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        $path = is_array($value) ? reset($value) : $value;
        if (! is_string($path) || ! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        try {
            return Storage::disk(self::REFERENCE_DISK)->url($path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function routeModelOptions(): array
    {
        $options = [];
        foreach (cms()->builder('routeModels') ?? [] as $modelConfig) {
            $class = $modelConfig['class'] ?? null;
            if ($class && class_exists($class)) {
                $options[$class] = $modelConfig['name'] ?? class_basename($class);
            }
        }

        return $options;
    }
}
