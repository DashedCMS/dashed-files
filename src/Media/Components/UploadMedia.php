<?php

namespace Dashed\DashedFiles\Media\Components;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Component;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Media\Components\Concerns as BaseConcerns;

class UploadMedia extends Component implements HasForms
{
    use BaseConcerns\CanOpenMediaLibraryFolder;
    use InteractsWithForms;

    public int $uploadProgress = 0;

    public ?array $uploads = [];

    public function mount(): void
    {
        $this->uploadForm->fill();
    }

    public function render(): View
    {
        return view('media-library::media.livewire.upload-media');
    }

    protected function getForms(): array
    {
        return [
            'uploadForm' => $this->makeForm()
                ->schema([
                    FileUpload::make('uploads')
                        ->label(Str::of(__('filament-media-library::translations.phrases.upload-file'))->ucfirst())
                        ->multiple()
                        ->preserveFilenames()
                        ->maxSize(round(config('media-library.max_file_size') / 1000))
                        ->acceptedFileTypes(FilamentMediaLibrary::get()->getAcceptedFileTypes())
                        ->required(),
                ]),
        ];
    }

    public function uploadFiles(): void
    {
        $this->validate();

        foreach ($this->uploads as $upload) {
            $this->addUpload($upload);

            $this->uploadProgress++;
        }

        $this->uploads = [];
        $this->uploadProgress = 0;
        $this->uploadForm->fill();
        $this->dispatch('$refresh');
    }

    protected function addUpload(UploadedFile $upload): void
    {
        FilamentMediaLibrary::get()->getModelItem()::addUpload($upload, $this->mediaLibraryFolder);
    }
}
