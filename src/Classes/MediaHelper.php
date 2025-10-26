<?php

namespace Dashed\DashedFiles\Classes;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use Dashed\DashedFiles\Jobs\RegenerateMediaLibraryConversions;
use RalphJSmit\Filament\MediaLibrary\Forms\Components\MediaPicker;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;
use RalphJSmit\Filament\Upload\Filament\Forms\Components\AdvancedFileUpload;
use RalphJSmit\Filament\MediaLibrary\Media\DataTransferObjects\MediaItemMeta;

class MediaHelper extends Command
{
    public function field($name = 'image', $label = 'Afbeelding', bool $required = false, bool $multiple = false, bool $isImage = false, null|int|string $defaultFolder = null): MediaPicker|AdvancedFileUpload
    {
        //        $mediaPicker = AdvancedFileUpload::make($name)
        //            ->label($label)
        //            ->required($required)
        //            ->multiple($multiple)
        //            ->downloadable()
        //            ->reorderable();

        $mediaPicker = MediaPicker::make($name)
            ->label($label)
            ->required($required)
            ->multiple($multiple)
            ->showFileName()
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
<<<<<<< HEAD
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
=======
            ->navigationSort(1)
            ->navigationLabel('Media Browser')
            ->navigationIcon('heroicon-o-camera')
            ->activeNavigationIcon('heroicon-s-camera')
            ->pageTitle('Media Browser')
            ->acceptPdf()
            ->acceptVideo()
            ->conversionResponsive(enabled: false, modifyUsing: function (Conversion $conversion) {
                // Apply any modifications you want to the conversion, or omit to use defaults...
                return $conversion->format('webp');
            })
            ->conversionMedium(enabled: false)
            ->conversionSmall(enabled: false)
            ->conversionThumb(enabled: true, width: 600, height: 600, modifyUsing: function (Conversion $conversion) {
                return $conversion->format('webp');
            })
            ->firstAvailableUrlConversions([
                'thumb',
            ])
>>>>>>> 71fbb1c37b0816f83048d7ab091d54c91f1f87e7
            ->slug('media-browser');
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

    public function getSingleMedia(null|int|string|array|MediaItemMeta $mediaId, array|string $conversion = 'medium'): string|array|MediaLibraryItem
    {
        if (! $mediaId) {
            return '';
        }

        if ($mediaId instanceof MediaItemMeta) {
            $mediaId = $mediaId->id;
        }

        if (is_string($mediaId) && filter_var($mediaId, FILTER_VALIDATE_INT) === false) {
            return $mediaId;
        }

        if (is_array($mediaId)) {
            $mediaId = $mediaId[0];
        }

        if (! is_int($mediaId)) {
            $mediaId = (int)$mediaId;
        }


        $conversionName = $this->getConversionName($conversion);

        if ($mediaId != 1516) {
            return '';
        }

        $cacheTag = 'media-library-media-' . $mediaId . '-' . $conversionName;
        $media = Cache::rememberForever($cacheTag, function () use ($mediaId, $conversion, $conversionName, $cacheTag) {
            $media = MediaLibraryItem::find($mediaId);
            if (! $media) {
                return '';
            }

            $mediaItem = $media->getItem();

            $hasCurrentConversion = false;
            $currentRegisteredConversions = json_decode($media->conversions ?: '{}', true);
            foreach ($currentRegisteredConversions as $registeredConversion) {
                if ($registeredConversion === $conversion) {
                    $hasCurrentConversion = true;
                }
            }

            if (! $hasCurrentConversion) {
                $currentRegisteredConversions[] = $conversion;
                $media->conversions = json_encode($currentRegisteredConversions);
                $media->save();
            }

            if (in_array($mediaItem->mime_type, ['image/svg+xml', 'image/svg', 'video/mp4', 'image/gif'])) {
                $conversionName = 'original';
            }

            $media->path = $mediaItem->getPath();
            if (str($mediaItem->mime_type)->contains('video/')) {
                $media->isVideo = true;
            } else {
                $media->isVideo = false;
            }
            if ($conversionName == 'original') {
                $media->url = $media->full_url;
            } else {
                if (! array_key_exists($conversionName, $mediaItem->generated_conversions) || $mediaItem->generated_conversions[$conversionName] !== true) {
                    RegenerateMediaLibraryConversions::dispatch($mediaItem->id, $cacheTag);
                }
                $media->url = $mediaItem->getAvailableUrl([$conversionName, 'medium']);
            }

            return $media;
        });

        return $media;
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

            return str($conversionString)->replace('--', '-');
        }

        return $conversion;
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
