<?php

namespace Dashed\DashedFiles\Classes;

use Spatie\Image\Enums\Fit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Filament\Forms\Components\TextInput;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Dashed\DashedFiles\Jobs\RegenerateMediaLibraryConversions;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\MediaPicker;

class MediaHelper extends Command
{
    public function field($name = 'image', $label = 'Afbeelding', bool $required = false, bool $multiple = false, bool $isImage = false, null|int|string $defaultFolder = null): TextInput|MediaPicker|AdvancedFileUpload
    {
        //        $mediaPicker = AdvancedFileUpload::make($name)
        //            ->label($label)
        //            ->required($required)
        //            ->multiple($multiple)
        //            ->downloadable()
        //            ->reorderable();

        //        return TextInput::make($name)
        //            ->label($label)
        //            ->placeholder('Media picker is tijdelijk uitgeschakeld')
        //            ->helperText('Media picker is tijdelijk uitgeschakeld');
        $mediaPicker = MediaPicker::make($name)
            ->label($label)
            ->required($required)
            ->multiple($multiple)
            ->downloadable()
            ->reorderable();

        if ($isImage) {
            $mediaPicker->acceptedFileTypes(['image/*']);
        }

        if ($defaultFolder) {
            if (is_string($defaultFolder)) {
                $defaultFolder = $this->getFolderId($defaultFolder);
            }

            $mediaPicker->defaultFolder(MediaLibraryFolder::find($defaultFolder));
        }

        return $mediaPicker;
    }

    public function plugin()
    {
        return FilamentMediaLibrary::make()
            ->navigationGroup('Content')
            ->navigationIcon('heroicon-o-camera')
            ->activeNavigationIcon('heroicon-s-camera')
            ->acceptPdf()
            ->acceptVideo()
            ->driver(modifyDriverUsing: function (MediaLibraryItemDriver $driver) {
                $driver
                    ->conversions()
                    ->conversionResponsive(enabled: true, modifyUsing: function (Conversion $conversion) {
                        return $conversion->format('webp');
                    })
                    ->conversionMedium(enabled: false, width: 800)
                    ->conversionSmall(enabled: false, width: 400)
                    ->conversionThumb(enabled: true, width: 600, height: 600, modifyUsing: function (Conversion $conversion) {
                        return $conversion->format('webp');
                    });

                $driver->registerConversions(function (MediaLibraryItem $mediaLibraryItem, Media $media = null) {
                    $mediaLibraryItemConversions = json_decode(MediaLibraryItem::find($media->model_id)->conversions ?? '{}', true);

                    foreach ($mediaLibraryItemConversions as $conversion) {
                        if (is_array($conversion)) {
                            foreach ($conversion as $key => $value) {
                                if ($key == 'widen') {
                                    $mediaLibraryItem
                                        ->addMediaConversion(mediaHelper()->getConversionName($conversion))
                                        ->format('webp')
                                        ->width(is_array($value) ? $value[0] : $value);
                                } elseif ($key == 'heighten') {
                                    $mediaLibraryItem
                                        ->addMediaConversion(mediaHelper()->getConversionName($conversion))
                                        ->format('webp')
                                        ->width(is_array($value) ? $value[0] : $value);
                                } elseif ($key == 'fit') {
                                    $mediaLibraryItem
                                        ->addMediaConversion(mediaHelper()->getConversionName($conversion))
                                        ->format('webp')
                                        ->fit(Fit::Crop, $value[0], $value[1]);
                                } elseif ($key == 'contain') {
                                    $mediaLibraryItem
                                        ->addMediaConversion(mediaHelper()->getConversionName($conversion))
                                        ->format('webp')
                                        ->fit(Fit::Contain, $value[0], $value[1]);
                                }
                            }
                        } elseif ($conversion == 'original') {
                            //Do nothing
                        } elseif ($conversion == 'huge') {
                            $mediaLibraryItem
                                ->addMediaConversion('huge')
                                ->format('webp')
                                ->width(1600);
                        } elseif ($conversion == 'large') {
                            $mediaLibraryItem
                                ->addMediaConversion('large')
                                ->format('webp')
                                ->width(1200);
                        } elseif ($conversion == 'small') {
                            $mediaLibraryItem
                                ->addMediaConversion('small')
                                ->format('webp')
                                ->width(400);
                        } elseif ($conversion == 'tiny') {
                            $mediaLibraryItem
                                ->addMediaConversion('tiny')
                                ->format('webp')
                                ->width(200);
                        }
                        $mediaLibraryItem
                            ->addMediaConversion('medium')
                            ->format('webp')
                            ->width(800);
                    }
                });
            })
            ->slug('media-browser');
    }

    private function getConversionData(MediaLibraryItem $item): array
    {
        try {
            return json_decode($item->conversion_urls ?? '[]', true) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function saveConversionData(MediaLibraryItem $item, array $data): void
    {
        $item->conversion_urls = json_encode($data);
        $item->save();
    }

    /**
     * @return $this
     * @deprecated
     *
     */
    public function getSingleImage(null|int|string|array $mediaId, array|string $conversion = 'medium'): string|MediaItemMeta
    {
        return $this->getSingleMedia($mediaId, $conversion);
    }

    public function getSingleMedia(null|int|string|array|MediaItemMeta $mediaId, array|string $conversion = 'medium')
    {
        if (! $mediaId) {
            return '';
        }

        if ($mediaId instanceof MediaItemMeta) {
            $mediaId = $mediaId->id;
        }

        // Als het geen integer-string is → we gaan er vanuit dat het al een URL/tekst is
        if (is_string($mediaId) && ! ctype_digit($mediaId)) {
            return $mediaId;
        }

        if (is_array($mediaId)) {
            $mediaId = $mediaId[0] ?? null;
        }

        if (! $mediaId) {
            return '';
        }

        $mediaId = (int) $mediaId;
        $conversionName = $this->getConversionName($conversion) ?: 'original';

        // ✅ Cache vóór we iets uit de DB halen
        $cacheKey = "media-library-media-{$mediaId}-{$conversionName}";

        return Cache::rememberForever($cacheKey, function () use ($mediaId, $conversionName, $conversion) {
            /** @var MediaLibraryItem|null $item */
            $item = MediaLibraryItem::find($mediaId);

            if (! $item) {
                return '';
            }

            // 1) JSON uit kolom conversion_urls halen
            $all = $this->getConversionData($item);

            // 2) Staat deze conversion er al in? → direct object teruggeven, geen Spatie-call
            if (isset($all[$conversionName])) {
                return (object) $all[$conversionName];
            }

            // 3) Zware pad: Spatie Media ophalen + URL genereren
            $spatie = $item->getItem(); // Spatie\MediaLibrary\Media

            $mime = $spatie->mime_type;
            $isVideo = str($mime)->startsWith('video/');

            // Niet-image types altijd op original houden
            $isNonImage = str($mime)->contains([
                'video/',
                'application/pdf',
                'image/svg',
                'image/svg+xml',
                'image/gif',
            ]);

            $effective = $isNonImage ? 'original' : $conversionName;

            // conversions array updaten als deze conversion nog niet geregistreerd is
            if ($effective !== 'original') {
                $currentRegistered = json_decode($item->conversions ?: '[]', true) ?: [];

                if (! in_array($conversion, $currentRegistered, true)) {
                    $currentRegistered[] = $conversion;
                    $item->conversions = json_encode($currentRegistered);
                }
            }

            // URL opvragen (de zware calls, maar nu alleen hier)
            if ($effective === 'original') {
                $url = $spatie->getUrl();
            } else {
                $generated = $spatie->generated_conversions ?? [];

                if (! ($generated[$effective] ?? false)) {
                    RegenerateMediaLibraryConversions::dispatch(
                        $spatie->id,
                        null,
                        $effective,
                        $generated
                    );
                }

                $url = $spatie->getAvailableUrl([$effective, 'medium']);
            }

            $width = $spatie->width;
            $height = $spatie->height;

            // 4) Opslaan in JSON zodat volgende call zelfs zonder cache-hit licht is
            $all[$conversionName] = [
                'id' => $mediaId,
                'url' => $url,
                'width' => $width,
                'height' => $height,
                'mime' => $mime,
                'path' => $spatie->getPath(),
                'is_video' => $isVideo,
            ];

            $this->saveConversionData($item, $all);

            return (object) $all[$conversionName];
        });
    }

    public function getMultipleMedia(array $mediaIds, string $conversion = 'medium'): ?Collection
    {
        if (is_string($mediaIds)) {
            return null;
        }

        if (is_int($mediaIds)) {
            return null;
        }

        $medias = [];

        foreach ($mediaIds as $id) {
            $medias[] = $this->getSingleMedia($id, $conversion);
        }

        return collect($medias);
    }

    public function getConversionName(string|array $conversion, $isChild = false): string
    {
        if (is_array($conversion)) {
            $conversionString = '';
            foreach ($conversion as $key => $conv) {
                if (! is_int($key)) {
                    $conversionString .= "$key-";
                }
                if ($isChild) {
                    $conversionString .= '-';
                }
                if (is_array($conv)) {
                    $conversionString .= $this->getConversionName($conv, true);
                } else {
                    $conversionString .= "$conv";
                }
            }

            return str($conversionString)->replace('--', '-') ?: 'original';
        }

        return $conversion ?: 'original';
    }

    public function getFolderPath(?int $folderId = null): string
    {
        $folder = MediaLibraryFolder::find($folderId);
        $path = '/';

        if ($folder) {
            foreach ($folder->getAncestors() as $ancestor) {
                $path .= $ancestor->name . '/';
            }
        }

        return trim(rtrim($path, '/'), '/');
    }

    public function getFolderId($folder): int
    {
        $folders = str($folder)->explode('/');
        $parentId = null;

        foreach ($folders as $folder) {
            $mediaFolder = MediaLibraryFolder::where('name', $folder)->where('parent_id', $parentId)->first();
            if (! $mediaFolder) {
                $mediaFolder = new MediaLibraryFolder();
                $mediaFolder->name = $folder;
                $mediaFolder->parent_id = $parentId;
                $mediaFolder->save();
            }
            $parentId = $mediaFolder->id;
        }

        return $mediaFolder->id;
    }

    public function getFileId(string $file, ?int $folderId = null): ?int
    {
        foreach (MediaLibraryItem::where('folder_id', $folderId)->get() as $media) {
            if (str($media->getItem()->getPath())->endsWith($file)) {
                return $media->id;
            }
        }

        return null;
    }

    public function downloadImage(string $path)
    {

    }

    public function uploadFromPath($path, $folder, bool $isExternalImage = false): ?int
    {
        $folderId = $this->getFolderId($folder);

        if ($existingFile = $this->getFileId($path, $folderId)) {
            return $existingFile;
        }

        if ($isExternalImage) {
            try {
                $response = Http::timeout(3)->retry(3)->get($path);
            } catch (\Exception $e) {
                return null;
            }
            $fileContent = $response->body();
            $fileType = $response->header('Content-Type');
            $fileName = basename($path);
            if (! str($fileName)->contains('.')) {
                $fileName .= '.' . str($fileType)->explode('/')[1];
            }
            $path = '/tmp/' . $fileName;
            Storage::disk('dashed')->put($path, $fileContent);
        }

        try {
            $filamentMediaLibraryItem = new MediaLibraryItem();
            $filamentMediaLibraryItem->uploaded_by_user_id = null;
            $filamentMediaLibraryItem->folder_id = $folderId;
            $filamentMediaLibraryItem->save();

            $fileName = basename($path);
            //            if (str($fileName)->length() > 200) {
            //                $newFileName = str(str($fileName)->explode('/')->last())->substr(50);
            //                $newFile = str($file)->replace($fileName, $newFileName);
            //                Storage::disk('dashed')->copy($file, $newFile);
            //                $file = $newFile;
            //            }

            try {
                $filamentMediaLibraryItem
                    ->addMediaFromDisk($path, 'dashed')
                    ->preservingOriginal()
                    ->toMediaCollection($filamentMediaLibraryItem->getMediaLibraryCollectionName());
            } catch (\Exception $e) {
                $filamentMediaLibraryItem->delete();

                return null;
            }

            return $filamentMediaLibraryItem->id;

        } catch (\Exception $e) {
            return null;
        }

        return null;
    }
}
