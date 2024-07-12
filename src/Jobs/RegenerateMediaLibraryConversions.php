<?php

namespace Dashed\DashedFiles\Jobs;

use Dashed\DashedCore\Classes\Locales;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedCore\Models\UrlHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class RegenerateMediaLibraryConversions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1200;
    public ?int $mediaId;
    public ?int $cacheTag;

    /**
     * Create a new job instance.
     */
    public function __construct(int $mediaId, string $cacheTag)
    {
        $this->mediaId = $mediaId;
        $this->cacheTag = $cacheTag;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('media-library:regenerate', ['--ids' => $this->mediaId]);
        Cache::forget($this->cacheTag);
    }
}
