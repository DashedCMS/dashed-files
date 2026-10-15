<?php

namespace Dashed\DashedFiles\Filament\Forms\Components;

use Throwable;
use Illuminate\Support\Js;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use RalphJSmit\Filament\Explore\Enums\FileType;
use Illuminate\Auth\Access\AuthorizationException;
use Filament\Forms\Components\Concerns\HasFileAttachments;
use RalphJSmit\Filament\Explore\Authorization\FileAbility;
use RalphJSmit\Filament\Explore\Data\TemporaryFileUploadData;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\MediaPicker;

/**
 * De MediaPicker uit de mediabibliotheek, plus uploaden zonder de modal:
 * een bestand op het veld slepen (of de knop "Uploaden" naast het label)
 * zet het via Livewire's uploadkanaal in de mediabibliotheek en koppelt het
 * meteen aan dit veld.
 *
 * Het uploadkanaal is dat van Filament's bijlagen (`componentFileAttachments`),
 * want dat is het enige pad waarlangs een los schemaveld een bestand mag
 * ontvangen; de trait `HasFileAttachments` is wat Filament daarvoor controleert.
 * Opslaan loopt via `Driver::createFile()`, dezelfde route als de uploadknop
 * in de modal, dus uploader, map, conversies en de uploadwachter gelden ook
 * hier. De browserkant staat in `dashed-files::filament.media-picker-upload`.
 */
class UploadableMediaPicker extends MediaPicker
{
    use HasFileAttachments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileAttachments(true);
        $this->fileAttachmentsMaxSize(null);
        $this->fileAttachmentsAcceptedFileTypes(fn (self $component): ?array => $component->getAcceptedFileTypes()->values()->all() ?: null);
        $this->saveUploadedFileAttachmentUsing(fn (self $component, TemporaryUploadedFile $file): string => $component->storeUploadedFile($file));

        $this->extraAttributes(fn (self $component): array => $component->getUploadZoneAttributes(), merge: true);

        $this->hintAction(fn (self $component): Action => $component->getUploadFileAction());
    }

    public function getUploadFileAction(): Action
    {
        return Action::make('uploadFile')
            ->label(__('Uploaden'))
            ->icon('heroicon-m-arrow-up-tray')
            ->color('gray')
            ->visible(fn (): bool => $this->canUpload())
            ->alpineClickHandler("\$dispatch('dashed-media-picker-pick', { id: " . Js::from($this->getId()) . " })");
    }

    public function canUpload(): bool
    {
        if ($this->isDisabled()) {
            return false;
        }

        return rescue(
            fn (): bool => $this->getDriver()->authorize(FileAbility::Create, FileType::File, $this->getDefaultFolder())->allowed(),
            rescue: false,
            report: false,
        );
    }

    /**
     * @return array<string, string>
     */
    public function getUploadZoneAttributes(): array
    {
        if (! $this->canUpload()) {
            return [];
        }

        $config = json_encode([
            'id' => $this->getId(),
            'key' => $this->getKey(),
            'statePath' => $this->getStatePath(),
            'multiple' => $this->isMultiple(),
            'accept' => $this->getAcceptedFileTypes()->values()->all(),
            'labels' => [
                'uploading' => __('Bezig met uploaden (:percent%)'),
                'notAccepted' => __('Dit bestandstype is hier niet toegestaan'),
                'onlyOne' => __('Dit veld neemt één bestand; alleen het eerste is geüpload'),
                'failed' => __('Uploaden mislukt'),
            ],
        ], JSON_UNESCAPED_UNICODE);

        return [
            'data-dashed-media-picker' => $config,
            'x-on:dragover.prevent' => 'window.dashedMediaPicker?.dragOver($event, $el)',
            'x-on:dragleave' => 'window.dashedMediaPicker?.dragLeave($event, $el)',
            'x-on:drop.prevent' => 'window.dashedMediaPicker?.drop($event, $el, $wire)',
            'x-on:dashed-media-picker-pick.window' => 'window.dashedMediaPicker?.pick($event, $el, $wire)',
        ];
    }

    /**
     * Zet een via Livewire geüpload bestand in de mediabibliotheek, in de
     * standaardmap van dit veld (of de hoofdmap), en geeft de sleutel terug
     * waarmee het in de state van de picker staat.
     */
    public function storeUploadedFile(TemporaryUploadedFile $file): string
    {
        $driver = $this->getDriver();
        $folder = $this->getDefaultFolder();

        if (! $driver->authorize(FileAbility::Create, FileType::File, $folder)->allowed()) {
            throw new AuthorizationException(__('Je mag hier geen bestanden uploaden'));
        }

        $fileData = $driver->createFile($folder, TemporaryFileUploadData::fromUploadedFile($file));

        return (string) $fileData->getPrimaryKey();
    }

    /**
     * Wordt door de browser aangeroepen zodra de uploads naar
     * `componentFileAttachments.<statePath>.<sleutel>` klaar zijn.
     *
     * @param  array<int, string>  $fileKeys
     */
    #[ExposedLivewireMethod]
    public function attachUploadedFiles(array $fileKeys): void
    {
        if ($this->isDisabled()) {
            return;
        }

        $added = [];
        $rejected = 0;

        foreach ($fileKeys as $fileKey) {
            $fileKey = (string) $fileKey;
            $attachment = $this->getUploadedFileAttachment($fileKey);

            if (! $attachment) {
                if ($this->forgetUploadedFileAttachment($fileKey)) {
                    $rejected++;
                }

                continue;
            }

            try {
                $added[] = $this->saveUploadedFileAttachment($attachment);
            } catch (AuthorizationException $exception) {
                Notification::make()->title($exception->getMessage())->danger()->send();
            } catch (Throwable $exception) {
                report($exception);

                Notification::make()
                    ->title(__('Uploaden mislukt'))
                    ->body($attachment->getClientOriginalName())
                    ->danger()
                    ->send();
            } finally {
                rescue(fn () => $attachment->delete(), report: false);
                $this->forgetUploadedFileAttachment($fileKey);
            }
        }

        if ($rejected) {
            Notification::make()->title(__('Dit bestandstype is hier niet toegestaan'))->danger()->send();
        }

        $added = array_values(array_filter($added));

        if (! $added) {
            return;
        }

        if ($this->isMultiple()) {
            $state = array_values(array_unique([...array_map('strval', (array) $this->getState()), ...$added]));

            if ($max = $this->getMaxFiles()) {
                $state = array_slice($state, 0, $max);
            }
        } else {
            $state = $added[0];
        }

        $this->state($state);
        $this->callAfterStateUpdated();

        Notification::make()
            ->title(count($added) === 1 ? __('Bestand geüpload en aan dit veld gekoppeld') : __('Bestanden geüpload en aan dit veld gekoppeld'))
            ->success()
            ->send();
    }

    protected function forgetUploadedFileAttachment(string $fileKey): bool
    {
        $livewire = $this->getLivewire();
        $path = "{$this->getStatePath()}.{$fileKey}";

        if (! data_get($livewire->componentFileAttachments, $path)) {
            return false;
        }

        data_forget($livewire->componentFileAttachments, $path);

        return true;
    }
}
