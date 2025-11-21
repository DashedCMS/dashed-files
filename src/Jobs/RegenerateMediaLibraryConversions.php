<?php

namespace Dashed\DashedFiles\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RegenerateMediaLibraryConversions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $timeout = 1200;
    public ?int $mediaId;
    public ?string $cacheTag;
    public ?string $conversionName;
    public ?array $generatedConversion;

    /**
     * Create a new job instance.
     */
    public function __construct(int $mediaId, ?string $cacheTag = null, ?string $conversionName, ?array $generatedConversion = null)
    {
        $this->mediaId = $mediaId;
        $this->cacheTag = $cacheTag;
        $this->conversionName = $conversionName;
        $this->generatedConversion = $generatedConversion;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('media-library:regenerate', ['--ids' => $this->mediaId, '--force' => true, '--only-missing']);
        Cache::forget($this->cacheTag);
    }
}
