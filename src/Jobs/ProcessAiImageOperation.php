<?php

namespace Dashed\DashedFiles\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedFiles\Models\AiImageOperation;
use Dashed\DashedFiles\Services\AiImageOperations;
use Dashed\DashedFiles\Events\AiImageOperationCompleted;

class ProcessAiImageOperation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [10, 30, 60];

    public function __construct(public int $operationId)
    {
    }

    public function handle(): void
    {
        $op = AiImageOperation::find($this->operationId);

        if (! $op || $op->status === AiImageOperation::STATUS_DONE) {
            return;
        }

        $op->update(['status' => AiImageOperation::STATUS_PROCESSING]);

        $resultId = $this->run($op, app(AiImageOperations::class));

        if (! $resultId) {
            // Gooi zodat Laravel het opnieuw probeert met backoff; failed() zet de
            // definitieve FAILED-status na de laatste poging.
            throw new \RuntimeException('De bewerking leverde geen resultaat op.');
        }

        $op->update([
            'status' => AiImageOperation::STATUS_DONE,
            'result_media_id' => $resultId,
        ]);

        AiImageOperationCompleted::dispatch($op->fresh());
    }

    public function failed(Throwable $exception): void
    {
        $op = AiImageOperation::find($this->operationId);

        if ($op && $op->status !== AiImageOperation::STATUS_DONE) {
            $op->update([
                'status' => AiImageOperation::STATUS_FAILED,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function run(AiImageOperation $op, AiImageOperations $service): ?int
    {
        $params = $op->params ?? [];

        if ($op->type === AiImageOperation::TYPE_GENERATE) {
            return $service->generate(
                $params['prompt'] ?? '',
                $params['ratio'] ?? '1:1',
                $params['reference_url'] ?? null,
                $op->site_id,
            );
        }

        $sourceUrl = $service->sourceUrl($op->source_media_id);

        if (! $sourceUrl) {
            return null;
        }

        return match ($op->type) {
            AiImageOperation::TYPE_REMOVE_BACKGROUND => $service->removeBackground($sourceUrl, $op->site_id),
            AiImageOperation::TYPE_UPSCALE => $service->upscale($sourceUrl, $op->site_id),
            AiImageOperation::TYPE_EDIT => $service->edit($sourceUrl, $params['prompt'] ?? '', $op->site_id),
            AiImageOperation::TYPE_PRODUCT_PHOTO => $service->productPhoto($sourceUrl, $op->site_id),
            default => null,
        };
    }
}
