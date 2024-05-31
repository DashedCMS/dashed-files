<?php

namespace Dashed\DashedFiles\MediaLibrary\Concerns;

use Illuminate\Support\Collection;

trait HasAcceptedFileTypesConfiguration
{
    protected bool $acceptImage = true;

    protected bool $acceptPdf = false;

    protected bool $acceptVideo = false;

    /**
     * @var array<array-key, string>
     */
    protected array $additionalAcceptedFileTypes = [];

    public function acceptImage(bool $accept = true): static
    {
        $this->acceptImage = $accept;

        return $this;
    }

    /**
     * In order to upload PDFs, you need to have the "spatie/pdf-to-image" package configured correctly.
     * This package is already required via Composer, but you need to make sure that the extension for
     * imagick has been installed and that Ghostscript is installed. Check out the following link:
     * https://github.com/spatie/pdf-to-image#requirements
     */
    public function acceptPdf(bool $accept = true): static
    {
        $this->acceptPdf = $accept;

        return $this;
    }

    /**
     * In order to let your users upload videos, you need to have the FFmpeg binary installed. See
     * the website of FFmpeg for installation instructions: https://ffmpeg.org/download.html. You
     * also need to install the "php-ffmpeg/php-ffmpeg" package and update the value of the config
     * "media-library.ffmpeg_path" and "media-library.ffmpeg_probe".
     */
    public function acceptVideo(bool $accept = true): static
    {
        $this->acceptVideo = $accept;

        return $this;
    }

    public function additionalAcceptedFileType(string $type): static
    {
        $this->additionalAcceptedFileTypes[] = $type;

        return $this;
    }

    public function additionalAcceptedFileTypes(array $types): static
    {
        $this->additionalAcceptedFileTypes = [
            ...$this->additionalAcceptedFileTypes,
            ...$types,
        ];

        return $this;
    }

    public function isImageAccepted(): bool
    {
        return $this->acceptImage;
    }

    public function isPdfAccepted(): bool
    {
        return $this->acceptPdf;
    }

    public function isVideoAccepted(): bool
    {
        return $this->acceptVideo;
    }

    public function getAdditionalAcceptedFileTypes(): array
    {
        return $this->additionalAcceptedFileTypes;
    }

    public function getAcceptedFileTypes(): Collection
    {
        return collect([
            'image/*' => $this->isImageAccepted(),
            'application/pdf' => $this->isPdfAccepted(),
            'video/*' => $this->isVideoAccepted(),
            'video/mp4' => $this->isVideoAccepted(),
        ])
            ->filter()
            ->keys()
            ->merge($this->getAdditionalAcceptedFileTypes());
    }
}
