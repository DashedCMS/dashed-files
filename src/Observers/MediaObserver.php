<?php

namespace Dashed\DashedFiles\Observers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use Dashed\DashedFiles\Exceptions\DisallowedFileTypeException;

class MediaObserver
{
    /**
     * Extensions that must never be stored, because the webserver could execute
     * them (PHP shells, CGI/templating scripts, native executables) or because
     * they reconfigure the webserver (.htaccess, .user.ini). Override per-project
     * via the `dashed-files.blocked_upload_extensions` config key.
     */
    public const BLOCKED_UPLOAD_EXTENSIONS = [
        // PHP in all its guises
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8',
        'phtml', 'pht', 'phps', 'phar', 'phtm', 'inc',
        // Other server-side / templating engines
        'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl', 'py', 'rb',
        'sh', 'bash', 'shtml', 'shtm', 'stm',
        // Native executables / scripts
        'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'vbs',
        'ws', 'wsf', 'jar',
        // Webserver / interpreter configuration
        'htaccess', 'htpasswd', 'ini',
    ];

    /**
     * Security guard. Runs for EVERY media upload (every Filament picker funnels
     * through the Spatie Media model), so a leaked/compromised admin account can
     * never store a file the webserver would execute. Defense-in-depth alongside
     * the webserver rule that blocks PHP execution under the public storage path.
     *
     * Checks every dot-separated component, so `shell.php.jpg` is blocked too.
     */
    public function creating(Media $media): void
    {
        $blocked = config('dashed-files.blocked_upload_extensions', self::BLOCKED_UPLOAD_EXTENSIONS);

        $parts = explode('.', strtolower((string) ($media->file_name ?? '')));
        array_shift($parts); // drop the base name, keep every extension component

        foreach ($parts as $part) {
            if (in_array(trim($part), $blocked, true)) {
                throw new DisallowedFileTypeException((string) ($media->file_name ?? ''), $part);
            }
        }
    }

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
