<?php

namespace Dashed\DashedFiles\Filament\Actions;

use Illuminate\Support\Collection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use RalphJSmit\Filament\Explore\Data\FileData;
use Dashed\DashedFiles\Models\AiImageOperation;
use RalphJSmit\Filament\Explore\Enums\FileType;
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
                    ->label(__('Wat moet er gebeuren?'))
                    ->placeholder(__('Bijv. verwijder het kabeltje rechtsonder'))
                    ->required()
                    ->rows(3),
            ])
            ->action(function (array $data, FileData $file) {
                if (self::refuseProtected($file)) {
                    return;
                }

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
            ->label(__('Genereer met AI'))
            ->icon('heroicon-o-sparkles')
            ->visible(fn () => AiImageOperations::isConfigured())
            ->schema([
                Textarea::make('prompt')->label(__('Prompt'))->required()->rows(4),
                Select::make('ratio')
                    ->label(__('Beeldverhouding'))
                    ->options([
                        '1:1' => __('1:1 (vierkant)'),
                        '4:5' => __('4:5 (portret)'),
                        '9:16' => __('9:16 (stories)'),
                        '16:9' => __('16:9 (landschap)'),
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
                    ->title(__('Beeld wordt gegenereerd'))
                    ->body(__('Het resultaat verschijnt zo in de map ai-generated.'))
                    ->success()
                    ->send();
            });
    }

    protected static function confirmingImageAction(string $name, string $type, string $label, string $icon): Action
    {
        return self::baseImageAction($name, $label, $icon)
            ->requiresConfirmation()
            ->action(function (FileData $file) use ($type) {
                if (self::refuseProtected($file)) {
                    return;
                }

                // Een upscale die de bibliotheek daarna toch zou weigeren: niet betalen.
                if ($type === AiImageOperation::TYPE_UPSCALE) {
                    $dimensions = AiImageOperations::dimensionsOf(self::mediaId($file));
                    $refusal = $dimensions === null ? null : AiImageOperations::upscaleRefusal($dimensions[0], $dimensions[1]);

                    if ($refusal !== null) {
                        Notification::make()->title(__('Niet geüpscaled'))->body($refusal)->danger()->send();

                        return;
                    }
                }

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
                // Een beschermd item (privé, chatbijlage, map chat of orders) gaat niet naar Fal.
                $ids = $files
                    ->filter(fn (FileData $file) => self::isImage($file))
                    ->map(fn (FileData $file) => self::mediaId($file))
                    ->reject(fn (int $id) => AiImageOperations::isProtectedSource($id))
                    ->values()
                    ->all();

                if (! $ids) {
                    Notification::make()->title(__('Geen afbeeldingen geselecteerd'))->warning()->send();

                    return;
                }

                app(AiImageOperations::class)->dispatchBulk(type: $type, sourceMediaIds: $ids);

                Notification::make()
                    ->title(__(':aantal afbeelding(en) in de wachtrij', ['aantal' => count($ids)]))
                    ->body(__('De bewerkte versies verschijnen zo in de bibliotheek.'))
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

    /** Weigert met een melding als het item niet naar een externe AI mag. */
    protected static function refuseProtected(FileData $file): bool
    {
        if (! AiImageOperations::isProtectedSource(self::mediaId($file))) {
            return false;
        }

        Notification::make()
            ->title(__('Niet verstuurd'))
            ->body(__('Deze afbeelding is privé of beschermd en gaat niet naar de AI-dienst.'))
            ->danger()
            ->send();

        return true;
    }

    protected static function notifyQueued(): void
    {
        Notification::make()
            ->title(__('Bezig met verwerken'))
            ->body(__('Het bewerkte beeld verschijnt zo in de bibliotheek.'))
            ->success()
            ->send();
    }
}
