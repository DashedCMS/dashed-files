<?php

namespace Dashed\DashedFiles\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Models\Customsetting;

class AiImageGenerator
{
    /**
     * Generate an image via fal.ai and import it into the media library.
     * Returns the MediaLibraryItem id on success, null on failure.
     *
     * When $referenceImageUrl is provided, uses nano-banana/edit with a
     * strict product-preservation prompt. Otherwise uses flux/dev.
     *
     * Blijft bestaan voor wie hem al aanroept; het werk zit in generateInto().
     *
     * @param  string|null  $siteId  If null, uses the default Customsetting scope.
     */
    public function generate(
        string $prompt,
        string $ratio = '1:1',
        ?string $referenceImageUrl = null,
        ?string $folder = 'ai-generated',
        ?string $siteId = null,
        array $context = [],
    ): ?int {
        $folderId = rescue(fn () => trim((string) $folder, "/ \t\n") === '' ? null : mediaHelper()->getFolderId((string) $folder), null);

        return $this->generateInto($prompt, $ratio, $referenceImageUrl, is_numeric($folderId) ? (int) $folderId : null, $siteId, $context);
    }

    /**
     * De ene plek die een afbeelding laat maken, voor de bron `ai` in het
     * keuzevenster, de oude hint-actie en de taak in de wachtrij. De
     * aanroep gaat via FalRequest (het AI-logboek) zodra dashed-ai er is.
     *
     * Vlak voor het versturen toetst AiSpend het dagplafond van de site en
     * legt de aanroep vast als AiImageOperation, via welke ingang ook. Is
     * het plafond bereikt, dan gaat er niets de deur uit en is de uitkomst
     * null, zoals bij elke andere mislukking (of AiCeilingReached met
     * `throw_on_limit`). Een aanroep die verstuurd is telt, ook als hij mislukt.
     *
     * @param  array{purpose?: string, timeout?: int, download_timeout?: int, user_id?: ?int, operation?: \Dashed\DashedFiles\Models\AiImageOperation, throw_on_limit?: bool}  $context
     *
     * @throws AiCeilingReached  alleen met `throw_on_limit`
     */
    public function generateInto(
        string $prompt,
        string $ratio = '1:1',
        ?string $referenceImageUrl = null,
        ?int $folderId = null,
        ?string $siteId = null,
        array $context = [],
    ): ?int {
        $apiKey = Customsetting::get('fal_api_key', $siteId);

        if (! $apiKey || ! trim($prompt)) {
            return null;
        }

        // De laatste deur voor Fal, voor elke aanroeper: een referentie die
        // naar een beschermd bestand van de bibliotheek wijst gaat niet mee.
        if ($referenceImageUrl && \Dashed\DashedFiles\Classes\MediaPrivacy::isProtectedUrl($referenceImageUrl)) {
            Log::warning('AiImageGenerator: protected reference image refused', ['site_id' => $siteId, 'purpose' => $context['purpose'] ?? 'media-generate']);

            return null;
        }

        [$endpoint, $payload] = $referenceImageUrl
            ? $this->buildEditRequest($prompt, $referenceImageUrl)
            : $this->buildTextToImageRequest($prompt, $ratio);

        $timeout = max(5, (int) ($context['timeout'] ?? 180));
        $userId = isset($context['user_id']) && is_numeric($context['user_id']) ? (int) $context['user_id'] : null;
        $claim = ($context['operation'] ?? null) instanceof \Dashed\DashedFiles\Models\AiImageOperation ? $context['operation'] : null;

        try {
            $charge = AiSpend::charge(AiSpend::KIND_GENERATE, $siteId, $claim, [
                'params' => ['prompt' => mb_substr($prompt, 0, 2000), 'ratio' => $ratio],
                'context' => array_filter(['purpose' => $context['purpose'] ?? 'media-generate', 'user_id' => $userId], fn ($value) => $value !== null),
            ]);
        } catch (AiCeilingReached $exception) {
            Log::warning('AiImageGenerator: daily ceiling reached', ['site_id' => $siteId, 'purpose' => $context['purpose'] ?? 'media-generate']);

            if ($context['throw_on_limit'] ?? false) {
                throw $exception;
            }

            return null;
        }

        $itemId = null;

        try {
            $response = class_exists(\Dashed\DashedAi\Logging\FalRequest::class)
                ? \Dashed\DashedAi\Logging\FalRequest::post($endpoint, $payload, $apiKey, ['site_id' => $siteId, 'purpose' => $context['purpose'] ?? 'media-generate'], $timeout)
                : Http::withHeaders([
                    'Authorization' => "Key {$apiKey}",
                    'Content-Type' => 'application/json',
                ])->timeout($timeout)->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('AiImageGenerator: request failed', [
                'error' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);
            AiSpend::settle($charge, null);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('AiImageGenerator: non-2xx response', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
                'endpoint' => $endpoint,
            ]);
            AiSpend::settle($charge, null);

            return null;
        }

        $imageUrl = $response->json('images.0.url');

        if (is_string($imageUrl) && $imageUrl !== '') {
            $itemId = AiImageOperations::importGeneratedInto($imageUrl, $folderId, [
                'operation' => $referenceImageUrl ? 'generate_from_reference' : 'generate',
                'model' => str_replace('https://fal.run/', '', $endpoint),
                'prompt' => $prompt,
                'ratio' => $ratio,
            ], $userId, isset($context['download_timeout']) && is_numeric($context['download_timeout']) ? (int) $context['download_timeout'] : null);
        }

        AiSpend::settle($charge, $itemId);

        return $itemId;
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
        return (bool) Customsetting::get('fal_api_key', $siteId);
    }
}
