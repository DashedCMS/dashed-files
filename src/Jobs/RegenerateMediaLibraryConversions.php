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
        // Een origineel dat gd niet in het geheugen past geeft een fatale
        // fout in de werker: overslaan met een melding, zoals de pomp.
        $media = rescue(fn () => \Spatie\MediaLibrary\MediaCollections\Models\Media::find($this->mediaId), null, false);

        if ($media) {
            $reason = \Dashed\DashedFiles\Classes\Conversions\ConversionPump::memoryShortfall(
                is_numeric($width = $media->getCustomProperty('original_width')) ? (int) $width : null,
                is_numeric($height = $media->getCustomProperty('original_height')) ? (int) $height : null,
                (int) $media->model_id,
            );

            if ($reason !== null) {
                app(\Dashed\DashedFiles\Classes\Conversions\ConversionPump::class)->warnMemorySkip((int) $media->model_id, $reason);

                return;
            }
        }

        // Hetzelfde slot als de pomp: elke schrijver zet de hele kolom
        // `generated_conversions` terug, dus twee tegelijk verliezen een formaat.
        $lock = Cache::lock(\Dashed\DashedFiles\Classes\Conversions\ConversionPump::mediaLockKey((int) $this->mediaId), 120);
        rescue(fn () => $lock->block(20), report: false);

        try {
            Artisan::call('media-library:regenerate', ['--ids' => $this->mediaId, '--force' => true, '--only-missing' => true]);
        } finally {
            rescue(fn () => $lock->release(), report: false);
        }
        // Cache::forget removed - MediaObserver::updated() clears the cache
        // when PerformConversionsJob finishes and updates the media model
    }
}
