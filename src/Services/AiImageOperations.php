<?php

namespace Dashed\DashedFiles\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Models\Customsetting;

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

    public static function isConfigured(?string $siteId = null): bool
    {
        try {
            return (bool) Customsetting::get('fal_api_key', $siteId);
        } catch (\Throwable) {
            return false;
        }
    }
}
