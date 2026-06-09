<?php

namespace Dashed\DashedFiles\Filament\Actions;

use Illuminate\Support\Collection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Enums\FileType;
use Dashed\DashedFiles\Models\AiImageOperation;
use Dashed\DashedFiles\Services\AiImageOperations;
use RalphJSmit\Filament\Explore\Filament\Actions\Action;
use RalphJSmit\Filament\Explore\Filament\Actions\BulkAction;

class AiMediaLibraryActions
{
    public static function removeBackground(): Action
    {
        return self::confirmingImageAction('ai_remove_background', AiImageOperation::TYPE_REMOVE_BACKGROUND, 'AI: achtergrond verwijderen', 'heroicon-o-scissors');
    }

    public static function upscale(): Action
    {
        return self::confirmingImageAction('ai_upscale', AiImageOperation::TYPE_UPSCALE, 'AI: upscalen', 'heroicon-o-arrows-pointing-out');
    }

    public static function productPhoto(): Action
    {
        return self::confirmingImageAction('ai_product_photo', AiImageOperation::TYPE_PRODUCT_PHOTO, 'AI: productfoto maken', 'heroicon-o-sparkles');
    }

    public static function retouch(): Action
    {
        return self::baseImageAction('ai_retouch', 'AI: retoucheren', 'heroicon-o-pencil-square')
            ->schema([
                Textarea::make('prompt')
                    ->label('Wat moet er gebeuren?')
                    ->placeholder('Bijv. verwijder het kabeltje rechtsonder')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (array $data, FileData $file) {
                app(AiImageOperations::class)->dispatchOne(
                    type: AiImageOperation::TYPE_EDIT,
                    sourceMediaId: self::mediaId($file),
                    params: ['prompt' => $data['prompt']],
                );

                self::notifyQueued();
            });
    }

    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            self::removeBackground(),
            self::upscale(),
            self::retouch(),
            self::productPhoto(),
        ];
    }

    /**
     * @return array<int, BulkAction>
     */
    public static function bulk(): array
    {
        return [
            self::bulkAction('ai_bulk_remove_background', AiImageOperation::TYPE_REMOVE_BACKGROUND, 'AI: achtergrond verwijderen', 'heroicon-o-scissors'),
            self::bulkAction('ai_bulk_upscale', AiImageOperation::TYPE_UPSCALE, 'AI: upscalen', 'heroicon-o-arrows-pointing-out'),
            self::bulkAction('ai_bulk_product_photo', AiImageOperation::TYPE_PRODUCT_PHOTO, 'AI: productfoto maken', 'heroicon-o-sparkles'),
        ];
    }

    public static function generate(): Action
    {
        return Action::make('ai_generate')
            ->label('Genereer met AI')
            ->icon('heroicon-o-sparkles')
            ->visible(fn () => AiImageOperations::isConfigured())
            ->schema([
                Textarea::make('prompt')->label('Prompt')->required()->rows(4),
                Select::make('ratio')
                    ->label('Beeldverhouding')
                    ->options([
                        '1:1' => '1:1 (vierkant)',
                        '4:5' => '4:5 (portret)',
                        '9:16' => '9:16 (stories)',
                        '16:9' => '16:9 (landschap)',
                    ])
                    ->default('1:1')
                    ->required(),
            ])
            ->action(function (array $data) {
                app(AiImageOperations::class)->dispatchOne(
                    type: AiImageOperation::TYPE_GENERATE,
                    params: ['prompt' => $data['prompt'], 'ratio' => $data['ratio']],
                );

                Notification::make()
                    ->title('Beeld wordt gegenereerd')
                    ->body('Het resultaat verschijnt zo in de map ai-generated.')
                    ->success()
                    ->send();
            });
    }

    protected static function confirmingImageAction(string $name, string $type, string $label, string $icon): Action
    {
        return self::baseImageAction($name, $label, $icon)
            ->requiresConfirmation()
            ->action(function (FileData $file) use ($type) {
                app(AiImageOperations::class)->dispatchOne(
                    type: $type,
                    sourceMediaId: self::mediaId($file),
                );

                self::notifyQueued();
            });
    }

    protected static function baseImageAction(string $name, string $label, string $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->visible(fn (FileData $file) => AiImageOperations::isConfigured() && self::isImage($file));
    }

    protected static function bulkAction(string $name, string $type, string $label, string $icon): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->visible(fn () => AiImageOperations::isConfigured())
            ->requiresConfirmation()
            ->action(function (Collection $files) use ($type) {
                $ids = $files
                    ->filter(fn (FileData $file) => self::isImage($file))
                    ->map(fn (FileData $file) => self::mediaId($file))
                    ->values()
                    ->all();

                if (! $ids) {
                    Notification::make()->title('Geen afbeeldingen geselecteerd')->warning()->send();

                    return;
                }

                app(AiImageOperations::class)->dispatchBulk(type: $type, sourceMediaIds: $ids);

                Notification::make()
                    ->title(count($ids).' afbeelding(en) in de wachtrij')
                    ->body('De bewerkte versies verschijnen zo in de bibliotheek.')
                    ->success()
                    ->send();
            });
    }

    protected static function isImage(FileData $file): bool
    {
        return $file->getType() === FileType::File && str_starts_with($file->getMimeType(), 'image/');
    }

    protected static function mediaId(FileData $file): int
    {
        return (int) $file->getSource()->getKey();
    }

    protected static function notifyQueued(): void
    {
        Notification::make()
            ->title('Bezig met verwerken')
            ->body('Het bewerkte beeld verschijnt zo in de bibliotheek.')
            ->success()
            ->send();
    }
}
