<?php

namespace Dashed\DashedFiles\Media\DataTransferObjects;

use Livewire\Wireable;

class MediaItemMeta implements Wireable
{
    public function __construct(
        public string $name,
        public string $human_readable_size,
        public string $uploaded_by_name,
        public string $uploaded_at,
        public string $folder_name,
        public ?int $width,
        public ?int $height,
        public string $url,
        public string $full_url,
        public ?string $altText,
        public ?string $caption,
        public int | string $id,
        public ?int $pdf_nr_of_pages,
        public ?string $video_duration,
    ) {
    }

    public static function fromLivewire($value)
    {
        return new static(...$value);
    }

    public function toLivewire(): array
    {
        return [
            'name' => $this->name,
            'human_readable_size' => $this->human_readable_size,
            'uploaded_by_name' => $this->uploaded_by_name,
            'uploaded_at' => $this->uploaded_at,
            'folder_name' => $this->folder_name,
            'width' => $this->width,
            'height' => $this->height,
            'url' => $this->url,
            'full_url' => $this->full_url,
            'altText' => $this->altText,
            'caption' => $this->caption,
            'id' => $this->id,
            'pdf_nr_of_pages' => $this->pdf_nr_of_pages,
            'video_duration' => $this->video_duration,
        ];
    }
}
