<?php

namespace Dashed\DashedFiles\Classes;

use Spatie\Image\Enums\Fit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Filament\Forms\Components\TextInput;
use RalphJSmit\Filament\Explore\Data\FileData;
use Spatie\MediaLibrary\Conversions\Conversion;
use Dashed\DashedFiles\Services\AiImageGenerator;
use Dashed\DashedFiles\Services\AiImageOperations;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidConversion;
use RalphJSmit\Filament\MediaLibrary\FilamentMediaLibrary;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Dashed\DashedFiles\Filament\Actions\AiGenerateImageAction;
use Dashed\DashedFiles\Filament\Actions\AiMediaLibraryActions;
use Dashed\DashedFiles\Jobs\RegenerateMediaLibraryConversions;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;
use Dashed\DashedFiles\Filament\Forms\Components\UploadableMediaPicker;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\MediaPicker;

class MediaHelper extends Command
{
    private static array $memoryCache = [];

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
        // De picker uit de mediabibliotheek, plus slepen-om-te-uploaden en de
        // knop "Uploaden" naast het label; de modal blijft er voor kiezen.
        $mediaPicker = UploadableMediaPicker::make($name)
            ->label($label)
            ->required($required)
            ->multiple($multiple)
            ->downloadable()
            ->reorderable();

        // Elk veld een eigen driver (die houdt per veld instellingen bij), maar
        // gevonden bestanden gedeeld over het hele verzoek. Zonder dit vroeg een
        // grote pagina bij elke opslag dezelfde media tientallen keren op.
        if (MediaPickerFileCache::isCompatible()) {
            $mediaPicker->driver(fn (MediaPicker $component) => CachingMediaLibraryItemDriver::forField($component));
        }

        if ($isImage) {
            $mediaPicker->acceptedFileTypes(['image/*']);
        }

        if ($defaultFolder) {
            if (is_string($defaultFolder)) {
                $defaultFolder = $this->getFolderId($defaultFolder);
            }

            $mediaPicker->defaultFolder(MediaLibraryFolder::find($defaultFolder));
        }

        $mediaPicker->hintAction(
            AiGenerateImageAction::make()->visible(fn () => AiImageGenerator::isConfigured())
        );

        return $mediaPicker;
    }

    public function plugin()
    {
        return FilamentMediaLibrary::make()
            ->navigationGroup(__('Content'))
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

                $driver->registerConversions(function (MediaLibraryItem $mediaLibraryItem, ?Media $media = null) {
                    // Spatie roept dit aan op een lege `new MediaLibraryItem`, dus de
                    // eigen conversies moeten van de rij komen. Dat gebeurde bij elke
                    // afbeeldings-URL opnieuw; per verzoek één keer per item is genoeg.
                    if ($mediaLibraryItem->exists && $media && $mediaLibraryItem->getKey() == $media->model_id) {
                        $conversionsJson = $mediaLibraryItem->conversions;
                    } elseif ($media && $media->model_id !== null) {
                        $conversionsJson = MediaPickerFileCache::rememberConversions(
                            $media->model_id,
                            fn () => MediaLibraryItem::find($media->model_id)?->conversions,
                        );
                    } else {
                        $conversionsJson = MediaLibraryItem::find($media?->model_id)?->conversions;
                    }

                    $mediaLibraryItemConversions = json_decode($conversionsJson ?? '{}', true);

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
                            // Do nothing
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

                if (AiImageOperations::isConfigured()) {
                    $driver
                        ->pushFileActions(AiMediaLibraryActions::all())
                        ->pushFileInfoActions(AiMediaLibraryActions::all())
                        ->pushBulkActions(AiMediaLibraryActions::bulk())
                        ->pushEmptyStateActions([
                            AiMediaLibraryActions::generate(),
                        ]);
                }
            })
            ->slug('media-browser');
    }

    /**
     * De in-process cache leeft zolang het proces leeft; een testsuite of
     * een langlopende worker die een ander item onder hetzelfde id ziet,
     * moet hem kunnen legen.
     */
    public static function flushMemoryCache(): void
    {
        static::$memoryCache = [];
    }

    private function decryptPickerKey(string $value): ?string
    {
        if (! class_exists(FileData::class)) {
            return null;
        }

        $key = rescue(fn () => FileData::decryptKeyHash($value), null, false);

        if (! is_string($key)) {
            return null;
        }

        // De mediabibliotheek zet een soortvoorvoegsel voor het id
        // ("media-library-item:2039"); een map ("media-library-folder:7")
        // is geen media en telt niet.
        if (str_contains($key, ':') && ! str_starts_with($key, 'media-library-item:')) {
            return null;
        }

        $id = (string) str($key)->afterLast(':');

        return ctype_digit($id) ? $id : null;
    }

    private function getConversionData(MediaLibraryItem $item): array
    {
        try {
            return json_decode($item->conversion_urls ?? '[]', true) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Zoals Spatie's getAvailableUrl, maar slaat conversies over die wel als
     * gegenereerd op de media staan maar niet (meer) op het item geregistreerd zijn.
     * Spatie gooit daar anders een InvalidConversion op.
     */
    private function getAvailableUrl(Media $spatie, array $conversionNames): string
    {
        foreach ($conversionNames as $conversionName) {
            if (! $spatie->hasGeneratedConversion($conversionName)) {
                continue;
            }

            try {
                return $spatie->getUrl($conversionName);
            } catch (InvalidConversion) {
                continue;
            }
        }

        return $spatie->getUrl();
    }

    private function saveConversionData(MediaLibraryItem $item, array $data): void
    {
        $item->conversion_urls = json_encode($data);
        $item->save();
    }

    /**
     * @return $this
     *
     * @deprecated
     */
    public function getSingleImage(null|int|string|array $mediaId, array|string $conversion = 'medium')
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

        if (is_array($mediaId)) {
            $mediaId = $mediaId[0] ?? null;
        }

        if (is_string($mediaId) && ! ctype_digit($mediaId)) {
            // De Livewire-staat van een mediakiezer draagt geen id maar een
            // versleutelde sleutel (FileData::getKeyHash()); wie de
            // formulierstaat rechtstreeks rendert, zoals een preview, komt
            // daarmee hier. Alles wat geen sleutel is, is een geplakte URL
            // of tekst en gaat ongewijzigd terug.
            $mediaId = $this->decryptPickerKey($mediaId) ?? $mediaId;

            if (! ctype_digit($mediaId)) {
                return $mediaId;
            }
        }

        if (! $mediaId) {
            return '';
        }

        $mediaId = (int) $mediaId;
        $conversionName = $this->getConversionName($conversion) ?: 'original';
        $cacheKey = "media-library-media-{$mediaId}-{$conversionName}";

        // 1) In-process memory cache (zero cost)
        if (isset(static::$memoryCache[$cacheKey])) {
            return static::$memoryCache[$cacheKey];
        }

        // 2) Redis cache
        $result = Cache::rememberForever($cacheKey, function () use ($mediaId, $conversionName, $conversion) {
            $item = MediaLibraryItem::find($mediaId);

            return $this->resolveMediaUrl($item, $conversionName, $conversion, $mediaId);
        });

        static::$memoryCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Preload media URLs for multiple IDs in a single Redis MGET + single DB query.
     */
    public function preloadMediaUrls(array $mediaIds, array|string $conversion = 'medium'): void
    {
        $conversionName = $this->getConversionName($conversion) ?: 'original';

        // Filter and deduplicate
        $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds))));

        $keysToFetch = [];
        foreach ($mediaIds as $id) {
            $key = "media-library-media-{$id}-{$conversionName}";
            if (! isset(static::$memoryCache[$key])) {
                $keysToFetch[$id] = $key;
            }
        }

        if (empty($keysToFetch)) {
            return;
        }

        // Single Redis MGET for all keys
        $values = Cache::many(array_values($keysToFetch));

        $missingIds = [];
        foreach ($keysToFetch as $id => $key) {
            if ($values[$key] !== null) {
                static::$memoryCache[$key] = $values[$key];
            } else {
                $missingIds[] = $id;
            }
        }

        // Batch-load cache misses from DB in one query
        if (! empty($missingIds)) {
            $items = MediaLibraryItem::whereIn('id', $missingIds)->get()->keyBy('id');

            foreach ($missingIds as $id) {
                $key = "media-library-media-{$id}-{$conversionName}";
                $item = $items->get($id);
                $result = $this->resolveMediaUrl($item, $conversionName, $conversion, $id);

                Cache::forever($key, $result);
                static::$memoryCache[$key] = $result;
            }
        }
    }

    /**
     * Resolve a media URL from a MediaLibraryItem (shared by getSingleMedia and preloadMediaUrls).
     */
    private function resolveMediaUrl(?MediaLibraryItem $item, string $conversionName, array|string $conversion, int $mediaId): mixed
    {
        if (! $item) {
            return '';
        }

        // 1) JSON uit kolom conversion_urls halen
        $all = $this->getConversionData($item);

        // 2) Staat deze conversion er al in? → direct object teruggeven.
        //    altText komt bewust niet uit conversion_urls maar altijd vers van het
        //    item: die JSON wordt eenmalig weggeschreven, dus een later aangepaste
        //    alt-tekst zou er anders nooit meer doorheen komen.
        if (isset($all[$conversionName])) {
            return (object) array_merge($all[$conversionName], ['altText' => $item->alt_text]);
        }

        // 3) Zware pad: Spatie Media ophalen + URL genereren
        $spatie = $item->getItem();

        $mime = $spatie->mime_type;
        $isVideo = str($mime)->startsWith('video/');

        $isNonImage = str($mime)->contains([
            'video/',
            'application/pdf',
            'image/svg',
            'image/svg+xml',
            'image/gif',
        ]);

        $effective = $isNonImage ? 'original' : $conversionName;

        if ($effective !== 'original') {
            $currentRegistered = json_decode($item->conversions ?: '[]', true) ?: [];

            if (! in_array($conversion, $currentRegistered, true)) {
                $currentRegistered[] = $conversion;
                $item->conversions = json_encode($currentRegistered);
                // Spatie registreert conversies via een lege MediaLibraryItem en leest
                // ze dus uit de database; zonder opslaan kent hij deze conversie niet.
                $item->saveQuietly();
                MediaPickerFileCache::forgetConversions($item->getKey());
            }
        }

        if ($effective === 'original') {
            $url = $spatie->getUrl();
        } else {
            $generated = $spatie->generated_conversions ?? [];

            if (! ($generated[$effective] ?? false)) {
                $lockKey = "regenerate-media-{$spatie->id}-{$effective}";
                if (! Cache::has($lockKey)) {
                    Cache::put($lockKey, true, 300);
                    RegenerateMediaLibraryConversions::dispatch(
                        $spatie->id,
                        null,
                        $effective,
                        $generated
                    );
                }
            }

            $url = $this->getAvailableUrl($spatie, [$effective, 'medium']);
        }

        $all[$conversionName] = [
            'id' => $mediaId,
            'url' => $url,
            'width' => $spatie->getCustomProperty('original_width') ?? $spatie->width,
            'height' => $spatie->getCustomProperty('original_height') ?? $spatie->height,
            'mime' => $mime,
            'path' => $spatie->getPath(),
            'is_video' => $isVideo,
        ];

        $this->saveConversionData($item, $all);

        return (object) array_merge($all[$conversionName], ['altText' => $item->alt_text]);
    }

    public function getMultipleMedia(array $mediaIds, string $conversion = 'medium'): ?Collection
    {
        if (is_string($mediaIds) || is_int($mediaIds)) {
            return null;
        }

        $this->preloadMediaUrls($mediaIds, $conversion);

        return collect($mediaIds)->map(fn ($id) => $this->getSingleMedia($id, $conversion));
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
                $path .= $ancestor->name.'/';
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
            $fileType = $response->header('Content-Type');
            $fileName = basename($path);
            if (! str($fileName)->contains('.')) {
                $fileName .= '.'.str($fileType)->explode('/')[1];
            }

            if ($existingFile = $this->getFileId($fileName, $folderId)) {
                return $existingFile;
            }

            $fileContent = $response->body();
            if (! $fileContent) {
                return null;
            }

            $path = '/tmp/'.$fileName;
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
