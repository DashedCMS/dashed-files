<?php

namespace Dashed\DashedFiles\Services;

use Dashed\DashedCore\Models\Customsetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiImageGenerator
{
    /**
     * Generate an image via fal.ai and import it into the media library.
     * Returns the MediaLibraryItem id on success, null on failure.
     *
     * When $referenceImageUrl is provided, uses nano-banana/edit with a
     * strict product-preservation prompt. Otherwise uses flux/dev.
     *
     * @param  string|null  $siteId  If null, uses the default Customsetting scope.
     */
    public function generate(
        string $prompt,
        string $ratio = '1:1',
        ?string $referenceImageUrl = null,
        ?string $folder = 'ai-generated',
        ?string $siteId = null,
    ): ?int {
        $apiKey = Customsetting::get('fal_api_key', $siteId)
            ?: Customsetting::get('social_fal_api_key', $siteId);

        if (! $apiKey || ! trim($prompt)) {
            return null;
        }

        [$endpoint, $payload] = $referenceImageUrl
            ? $this->buildEditRequest($prompt, $referenceImageUrl)
            : $this->buildTextToImageRequest($prompt, $ratio);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Key {$apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(180)->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('AiImageGenerator: request failed', [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('AiImageGenerator: non-2xx response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $endpoint,
            ]);

            return null;
        }

        $imageUrl = $response->json('images.0.url');
        if (! $imageUrl) {
            return null;
        }

        return mediaHelper()->uploadFromPath($imageUrl, $folder, isExternalImage: true);
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function buildTextToImageRequest(string $prompt, string $ratio): array
    {
        return [
            'https://fal.run/fal-ai/flux/dev',
            [
                'prompt' => $prompt,
                'image_size' => $this->mapFluxImageSize($ratio),
                'num_images' => 1,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function buildEditRequest(string $prompt, string $referenceImageUrl): array
    {
        $editPrompt = 'Keep the product in the input image 100% identical: same exact shape, silhouette, '
            .'proportions, colors, materials, textures, logos, labels and every fine detail. '
            .'Do not redraw, restyle, recolor or reshape the product in any way. '
            .'Only change the background, environment, lighting and surrounding scene as described. '
            ."\n\nScene: ".trim($prompt);

        return [
            'https://fal.run/fal-ai/nano-banana/edit',
            [
                'prompt' => $editPrompt,
                'image_urls' => [$referenceImageUrl],
                'num_images' => 1,
                'output_format' => 'png',
            ],
        ];
    }

    private function mapFluxImageSize(string $ratio): string
    {
        return match ($ratio) {
            '1:1' => 'square',
            '1:1_hd' => 'square_hd',
            '4:5', '2:3', '3:4' => 'portrait_4_3',
            '9:16' => 'portrait_16_9',
            '4:3' => 'landscape_4_3',
            '16:9' => 'landscape_16_9',
            default => 'square',
        };
    }

    public static function isConfigured(?string $siteId = null): bool
    {
        return (bool) (
            Customsetting::get('fal_api_key', $siteId)
            ?: Customsetting::get('social_fal_api_key', $siteId)
        );
    }
}
