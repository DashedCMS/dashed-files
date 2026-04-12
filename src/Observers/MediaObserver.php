<?php

namespace Dashed\DashedFiles\Observers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;

class MediaObserver
{
    public function created(Media $media)
    {
        $this->storeOriginalDimensions($media);
    }

    public function updated(Media $media)
    {
        $filamentMedia = MediaLibraryItem::find($media->model_id);
        if ($filamentMedia) {
            $filamentMedia->conversion_urls = null;
            $filamentMedia->save();
            foreach (json_decode($filamentMedia->conversions ?: '{}', true) as $conversion) {
                Cache::forget('media-library-media-' . $filamentMedia->id . '-' . mediaHelper()->getConversionName($conversion));
            }
        }
    }

    protected function storeOriginalDimensions(Media $media): void
    {
        if ($media->getCustomProperty('original_width')) {
            return;
        }

        $mime = $media->mime_type ?? '';
        if (! str_starts_with($mime, 'image/') || str_contains($mime, 'svg')) {
            return;
        }

        try {
            $dimensions = $this->getImageDimensions($media);
            if (! $dimensions) {
                return;
            }

            $media->setCustomProperty('original_width', $dimensions[0]);
            $media->setCustomProperty('original_height', $dimensions[1]);
            $media->saveQuietly();
        } catch (\Throwable $e) {
            // Don't break upload flow
        }
    }

    public function getImageDimensions(Media $media): ?array
    {
        $tmp = null;

        try {
            // Try local path first
            $localPath = $media->getPath();
            if ($localPath && file_exists($localPath)) {
                $size = @getimagesize($localPath);

                return $size ? [$size[0], $size[1]] : null;
            }

            // Try reading from storage disk
            $disk = $media->disk;
            $relativePath = $media->getPathRelativeToRoot();

            if (Storage::disk($disk)->exists($relativePath)) {
                $tmp = tempnam(sys_get_temp_dir(), 'media-dim-');
                $stream = Storage::disk($disk)->readStream($relativePath);
                if ($stream) {
                    $fp = fopen($tmp, 'w');
                    stream_copy_to_stream($stream, $fp);
                    fclose($fp);
                    fclose($stream);

                    $size = @getimagesize($tmp);

                    return $size ? [$size[0], $size[1]] : null;
                }
            }

            return null;
        } finally {
            if ($tmp && file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
