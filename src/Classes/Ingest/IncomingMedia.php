<?php

namespace Dashed\DashedFiles\Classes\Ingest;

use Throwable;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class IncomingMedia
{
    /**
     * @param  string  $source  upload|url|ai|stock:<key>|api|chat|system
     * @param  array<string, mixed>  $meta  source_url, author, license, prompt, ai_operation_id, ...
     * @param  array<int, string>  $allowedMimes  leeg = de lijst van de bron; kan die lijst alleen versmallen
     */
    public function __construct(
        public readonly string $localPath,
        public readonly string $fileName,
        public readonly string $source = 'upload',
        public readonly ?int $folderId = null,
        public readonly ?int $uploadedByUserId = null,
        public readonly array $meta = [],
        public readonly array $allowedMimes = [],
        public readonly bool $deleteLocalAfter = true,
    ) {
    }

    public static function fromUploadedFile(UploadedFile $file, string $source = 'upload', ?int $folderId = null): self
    {
        $userId = auth()->id();

        return new self(
            localPath: (string) ($file->getRealPath() ?: $file->getPathname()),
            fileName: (string) $file->getClientOriginalName(),
            source: $source,
            folderId: $folderId,
            uploadedByUserId: is_numeric($userId) ? (int) $userId : null,
        );
    }

    /**
     * Haalt het bestand van de schijf naar een eigen tijdelijke map. Het
     * origineel op de schijf blijft staan; dat ruimt de aanroeper op.
     *
     * @throws MediaRejectedException
     */
    public static function fromDisk(string $disk, string $path, string $source = 'system', ?int $folderId = null): self
    {
        $local = self::temporaryPath();

        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (Throwable) {
            $stream = null;
        }

        if (! is_resource($stream)) {
            throw new MediaRejectedException(__('Het bestand is niet gevonden.'));
        }

        $target = @fopen($local, 'wb');
        $copied = $target ? stream_copy_to_stream($stream, $target) : false;

        fclose($stream);

        if ($target) {
            fclose($target);
        }

        if ($copied === false) {
            @unlink($local);

            throw new MediaRejectedException(__('Het bestand kon niet worden gelezen.'));
        }

        return new self(localPath: $local, fileName: basename(str_replace('\\', '/', $path)), source: $source, folderId: $folderId);
    }

    public function uploadedBy(?int $userId): self
    {
        return new self($this->localPath, $this->fileName, $this->source, $this->folderId, $userId, $this->meta, $this->allowedMimes, $this->deleteLocalAfter);
    }

    /**
     * Een pad in storage/app/media-ingest: buiten elke publieke schijf, en
     * zonder extensie zodat een webserver er nooit iets mee uitvoert.
     *
     * @throws MediaRejectedException
     */
    public static function temporaryPath(): string
    {
        $directory = storage_path('app/media-ingest');

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new MediaRejectedException(__('Het tijdelijke bestand kon niet worden aangemaakt.'));
        }

        return $directory . DIRECTORY_SEPARATOR . Str::random(40);
    }
}
