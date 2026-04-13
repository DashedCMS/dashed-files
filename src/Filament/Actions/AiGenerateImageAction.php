<?php

namespace Dashed\DashedFiles\Filament\Actions;

use Dashed\DashedFiles\Services\AiImageGenerator;
use Dashed\DashedFiles\Services\SubjectImageResolver;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class AiGenerateImageAction
{
    /**
     * Build a Filament hint-action that opens a modal to generate an image via fal.ai
     * and set the resulting media library id onto the field it was attached to.
     */
    public static function make(): Action
    {
        return Action::make('aiGenerateImage')
            ->label('Genereer met AI')
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->modalHeading('Afbeelding genereren met AI')
            ->modalDescription('Beschrijf wat je wilt zien. Geef optioneel een referentieafbeelding mee voor 1-op-1 productbehoud (nano-banana/edit).')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Genereer')
            ->schema(self::buildSchema())
            ->action(function (array $data, $component) {
                $prompt = trim((string) ($data['prompt'] ?? ''));
                if (! $prompt) {
                    Notification::make()
                        ->title('Geen prompt opgegeven')
                        ->danger()
                        ->send();

                    return;
                }

                $reference = self::resolveReferenceUrl($data);
                $ratio = $data['ratio'] ?? '1:1';

                $mediaId = app(AiImageGenerator::class)->generate(
                    prompt: $prompt,
                    ratio: $ratio,
                    referenceImageUrl: $reference,
                );

                if (! $mediaId) {
                    Notification::make()
                        ->title('Genereren mislukt')
                        ->body('Check de logs of je fal.ai sleutel. Probeer het nog eens of pas de prompt aan.')
                        ->danger()
                        ->send();

                    return;
                }

                $component->state($mediaId);

                Notification::make()
                    ->title('Afbeelding gegenereerd')
                    ->body('Het resultaat is opgeslagen in de media library en gekoppeld aan dit veld.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private static function buildSchema(): array
    {
        return [
            Textarea::make('prompt')
                ->label('Prompt')
                ->placeholder('Bijv. Een minimalistische productfoto van een leren tas op een marmeren tafel, zacht daglicht, zachte schaduwen, beige achtergrond.')
                ->rows(4)
                ->required(),

            Select::make('ratio')
                ->label('Beeldverhouding')
                ->options([
                    '1:1' => '1:1 (vierkant)',
                    '4:5' => '4:5 (portret, Instagram feed)',
                    '9:16' => '9:16 (stories/reels)',
                    '2:3' => '2:3 (portret)',
                    '3:4' => '3:4 (portret)',
                    '4:3' => '4:3 (landschap)',
                    '16:9' => '16:9 (landschap)',
                ])
                ->default('1:1')
                ->required(),

            Radio::make('reference_source')
                ->label('Referentieafbeelding (optioneel)')
                ->helperText('Met referentie wordt het product 1-op-1 behouden via nano-banana/edit.')
                ->options([
                    'none' => 'Geen — tekst-naar-beeld (flux/dev)',
                    'url' => 'URL invoeren',
                    'upload' => 'Uploaden',
                    'model' => 'Kies uit onderwerp in de CMS',
                ])
                ->default('none')
                ->live(),

            TextInput::make('reference_url')
                ->label('Referentieafbeelding URL')
                ->url()
                ->visible(fn (callable $get) => $get('reference_source') === 'url'),

            FileUpload::make('reference_upload')
                ->label('Upload referentieafbeelding')
                ->image()
                ->disk('public')
                ->directory('ai-reference-temp')
                ->visibility('public')
                ->visible(fn (callable $get) => $get('reference_source') === 'upload'),

            Select::make('reference_model_type')
                ->label('Onderwerp type')
                ->options(self::routeModelOptions())
                ->nullable()
                ->live()
                ->afterStateUpdated(function (callable $set) {
                    $set('reference_model_id', null);
                    $set('reference_model_image', null);
                })
                ->visible(fn (callable $get) => $get('reference_source') === 'model'),

            Select::make('reference_model_id')
                ->label('Specifiek onderwerp')
                ->searchable()
                ->nullable()
                ->live()
                ->getSearchResultsUsing(function (string $search, callable $get) {
                    $class = $get('reference_model_type');
                    if (! $class || ! class_exists($class)) {
                        return [];
                    }
                    $model = new $class;

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
                    if (! $value || ! $class || ! class_exists($class)) {
                        return null;
                    }
                    $item = $class::find($value);

                    return $item ? ($item->name ?? $item->title ?? "#{$item->getKey()}") : null;
                })
                ->afterStateUpdated(fn (callable $set) => $set('reference_model_image', null))
                ->visible(fn (callable $get) => $get('reference_source') === 'model' && (bool) $get('reference_model_type')),

            Select::make('reference_model_image')
                ->label('Kies afbeelding van onderwerp')
                ->options(function (callable $get) {
                    $class = $get('reference_model_type');
                    $id = $get('reference_model_id');
                    if (! $class || ! $id || ! class_exists($class)) {
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
                ->label('Referentie voorbeeld')
                ->content(function (callable $get) {
                    $url = self::resolveReferenceUrl([
                        'reference_source' => $get('reference_source'),
                        'reference_url' => $get('reference_url'),
                        'reference_upload' => $get('reference_upload'),
                        'reference_model_image' => $get('reference_model_image'),
                    ]);

                    return $url
                        ? new HtmlString('<img src="'.e($url).'" style="max-height:180px;border-radius:8px;" />')
                        : '—';
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
            return Storage::disk('public')->url($path);
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
