<?php

namespace Dashed\DashedFiles\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedFiles\Jobs\ProcessAiImageOperation;
use Dashed\DashedFiles\Models\AiImageOperation;

class AiImageOperations
{
    private const PRODUCT_PHOTO_PROMPT = 'Place this exact product on a clean, seamless white studio background '
        .'with soft, even lighting and a subtle natural shadow beneath it. Keep the product 100% identical: '
        .'same shape, colors, materials, textures, logos and labels. Do not alter the product in any way. '
        .'Center the product with comfortable margins, e-commerce product photo style.';

    /**
     * Voer een fal.ai-call uit en geef de resultaat-URL terug (geen import).
     * Leest zowel images.0.url als image.url, want het veld verschilt per model.
     */
    protected function callFal(string $endpoint, array $payload, ?string $siteId = null): ?string
    {
        $apiKey = $this->apiKey($siteId);

        if (! $apiKey) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Key {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(180)->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('AiImageOperations: request failed', [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('AiImageOperations: non-2xx response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $endpoint,
            ]);

            return null;
        }

        return $response->json('images.0.url') ?: $response->json('image.url') ?: null;
    }

    /**
     * Importeer een externe URL als nieuw media-item en geef het id terug.
     */
    protected function import(?string $url, string $folder = 'ai-edited'): ?int
    {
        if (! $url) {
            return null;
        }

        return mediaHelper()->uploadFromPath($url, $folder, isExternalImage: true);
    }

    /**
     * Haal de fal-sleutel op. Eigen methode zodat tests dit kunnen stubben
     * zonder de Customsetting-laag (en daarmee dashed-core) te laden.
     */
    protected function apiKey(?string $siteId = null): ?string
    {
        return Customsetting::get('fal_api_key', $siteId);
    }

    /**
     * Zet een media-id om naar een publieke URL voor het origineel.
     */
    public function sourceUrl(?int $mediaId): ?string
    {
        if (! $mediaId) {
            return null;
        }

        $resolved = mediaHelper()->getSingleMedia($mediaId, 'original');

        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        if (is_object($resolved) && isset($resolved->url)) {
            return $resolved->url;
        }

        return null;
    }

    public function removeBackground(string $sourceUrl, ?string $siteId = null): ?int
    {
        $url = $this->callFal('https://fal.run/fal-ai/birefnet', [
            'image_url' => $sourceUrl,
        ], $siteId);

        return $this->import($url, 'ai-edited');
    }

    public function upscale(string $sourceUrl, ?string $siteId = null): ?int
    {
        $url = $this->callFal('https://fal.run/fal-ai/clarity-upscaler', [
            'image_url' => $sourceUrl,
        ], $siteId);

        return $this->import($url, 'ai-edited');
    }

    public function edit(string $sourceUrl, string $prompt, ?string $siteId = null): ?int
    {
        $url = $this->callFal('https://fal.run/fal-ai/nano-banana/edit', [
            'prompt' => $prompt,
            'image_urls' => [$sourceUrl],
            'num_images' => 1,
            'output_format' => 'png',
        ], $siteId);

        return $this->import($url, 'ai-edited');
    }

    public function productPhoto(string $sourceUrl, ?string $siteId = null): ?int
    {
        $cutout = $this->callFal('https://fal.run/fal-ai/birefnet', [
            'image_url' => $sourceUrl,
        ], $siteId);

        if (! $cutout) {
            Log::warning('AiImageOperations: product photo aborted, background removal step failed', [
                'sourceUrl' => $sourceUrl,
            ]);

            return null;
        }

        $studio = $this->callFal('https://fal.run/fal-ai/nano-banana/edit', [
            'prompt' => self::PRODUCT_PHOTO_PROMPT,
            'image_urls' => [$cutout],
            'num_images' => 1,
            'output_format' => 'png',
        ], $siteId);

        return $this->import($studio, 'ai-edited');
    }

    public function generate(
        string $prompt,
        string $ratio = '1:1',
        ?string $referenceImageUrl = null,
        ?string $siteId = null,
    ): ?int {
        return app(AiImageGenerator::class)->generate(
            prompt: $prompt,
            ratio: $ratio,
            referenceImageUrl: $referenceImageUrl,
            folder: 'ai-generated',
            siteId: $siteId,
        );
    }

    /**
     * Maak één operatie aan en dispatch de job. Geeft het AiImageOperation-id terug.
     */
    public function dispatchOne(
        string $type,
        ?int $sourceMediaId = null,
        array $params = [],
        ?string $batchId = null,
        ?string $siteId = null,
    ): int {
        $op = AiImageOperation::create([
            'type' => $type,
            'status' => AiImageOperation::STATUS_QUEUED,
            'source_media_id' => $sourceMediaId,
            'params' => $params,
            'batch_id' => $batchId,
            'site_id' => $siteId,
        ]);

        ProcessAiImageOperation::dispatch($op->id);

        return $op->id;
    }

    /**
     * Dispatch dezelfde bewerking voor meerdere media-items onder één batch_id.
     * Geeft het batch_id terug voor voortgangsweergave.
     */
    public function dispatchBulk(
        string $type,
        array $sourceMediaIds,
        array $params = [],
        ?string $siteId = null,
    ): string {
        $batchId = (string) Str::uuid();

        foreach ($sourceMediaIds as $mediaId) {
            $this->dispatchOne($type, (int) $mediaId, $params, $batchId, $siteId);
        }

        return $batchId;
    }

    public static function isConfigured(?string $siteId = null): bool
    {
        try {
            return (bool) Customsetting::get('fal_api_key', $siteId);
        } catch (\Throwable) {
            return false;
        }
    }
}
