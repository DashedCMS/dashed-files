<?php

namespace Dashed\DashedFiles\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Models\AiImageOperation;
use Dashed\DashedFiles\Jobs\ProcessAiImageOperation;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;

class AiImageOperations
{
    private const PRODUCT_PHOTO_PROMPT = 'Place this exact product on a clean, seamless white studio background '
        .'with soft, even lighting and a subtle natural shadow beneath it. Keep the product 100% identical: '
        .'same shape, colors, materials, textures, logos and labels. Do not alter the product in any way. '
        .'Center the product with comfortable margins, e-commerce product photo style.';

    /**
     * De operatie waarvoor deze dienst nu werkt (gezet door de taak): de
     * eerste betaalde aanroep telt als die operatie zelf, elke volgende
     * (een tweede stap, een herkansing) krijgt een eigen rij in het plafond.
     */
    protected ?AiImageOperation $operation = null;

    /** Met true gooit een bereikt plafond AiCeilingReached in plaats van null terug te geven. */
    protected bool $throwOnLimit = false;

    public function forOperation(?AiImageOperation $operation, bool $throwOnLimit = false): static
    {
        $this->operation = $operation;
        $this->throwOnLimit = $throwOnLimit;

        return $this;
    }

    /**
     * Voer een fal.ai-call uit en geef de resultaat-URL terug (geen import).
     * Leest zowel images.0.url als image.url, want het veld verschilt per model.
     *
     * Elke aanroep hier is een bewerking en telt voor het plafond voor
     * bewerkingen (AiSpend), vlak voordat hij verstuurd wordt.
     */
    protected function callFal(string $endpoint, array $payload, ?string $siteId = null, string $type = AiImageOperation::TYPE_EDIT): ?string
    {
        $apiKey = $this->apiKey($siteId);

        if (! $apiKey) {
            return null;
        }

        $claim = $this->operation !== null && $this->operation->type === $type ? $this->operation : null;

        try {
            AiSpend::charge(AiSpend::KIND_EDIT, $siteId, $claim, [
                'type' => $type,
                'params' => ['model' => str_replace('https://fal.run/', '', $endpoint)],
            ]);
        } catch (AiCeilingReached $exception) {
            Log::warning('AiImageOperations: daily ceiling reached', ['site_id' => $siteId, 'type' => $type]);

            if ($this->throwOnLimit) {
                throw $exception;
            }

            return null;
        }

        try {
            $response = class_exists(\Dashed\DashedAi\Logging\FalRequest::class)
                ? \Dashed\DashedAi\Logging\FalRequest::post($endpoint, $payload, $apiKey, ['site_id' => $siteId], 180)
                : Http::withHeaders([
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
                'body' => mb_substr((string) $response->body(), 0, 500),
                'endpoint' => $endpoint,
            ]);

            return null;
        }

        return $response->json('images.0.url') ?: $response->json('image.url') ?: null;
    }

    // Wat er bij de eerstvolgende import() als herkomst bewaard wordt. Een
    // eigenschap en geen argument: import() is een naad die tests met
    // precies twee argumenten nabootsen.
    protected array $importMeta = [];

    /**
     * Importeer een externe URL als nieuw media-item en geef het id terug.
     */
    protected function import(?string $url, string $folder = 'ai-edited'): ?int
    {
        if (! $url) {
            return null;
        }

        $meta = $this->importMeta;
        $this->importMeta = [];

        return self::importGenerated($url, $folder, $meta);
    }

    /**
     * Neemt een door fal gemaakte afbeelding in via de inname van de
     * bibliotheek, met bron `ai`. Geeft net als voorheen een id of null.
     */
    public static function importGenerated(string $url, string $folder, array $meta = []): ?int
    {
        $folderId = rescue(fn () => trim($folder, "/ \t\n") === '' ? null : mediaHelper()->getFolderId($folder), null);

        return self::importGeneratedInto($url, is_numeric($folderId) ? (int) $folderId : null, $meta);
    }

    /**
     * Zoals importGenerated(), in een map op id en met de gebruiker die erom vroeg.
     */
    public static function importGeneratedInto(string $url, ?int $folderId, array $meta = [], ?int $userId = null, ?int $downloadTimeout = null): ?int
    {
        try {
            $library = app(MediaLibrary::class);
            $incoming = $library->download($url, $folderId, 'ai', array_filter($meta, fn ($value) => $value !== null && $value !== ''), $downloadTimeout !== null && $downloadTimeout > 0 ? ['timeout' => $downloadTimeout] : []);

            return $library->ingest($userId === null ? $incoming : $incoming->uploadedBy($userId))->item->id;
        } catch (MediaRejectedException $exception) {
            Log::warning('AI-afbeelding geweigerd bij het innemen', ['url' => $url, 'reason' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return null;
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
        if (! $mediaId || self::isProtectedSource($mediaId)) {
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
        ], $siteId, AiImageOperation::TYPE_REMOVE_BACKGROUND);

        $this->importMeta = ['operation' => 'remove_background', 'model' => 'fal-ai/birefnet'];

        return $this->import($url, 'ai-edited');
    }

    /**
     * @param  array{0: ?int, 1: ?int}|null  $dimensions  breedte en hoogte van de bron, als die bekend zijn
     */
    public function upscale(string $sourceUrl, ?string $siteId = null, ?array $dimensions = null): ?int
    {
        // Vóór de betaalde aanroep: een resultaat dat de inname toch zou weigeren kost alleen geld.
        if ($dimensions !== null && self::upscaleRefusal($dimensions[0] ?? null, $dimensions[1] ?? null) !== null) {
            return null;
        }

        $url = $this->callFal('https://fal.run/fal-ai/clarity-upscaler', [
            'image_url' => $sourceUrl,
        ], $siteId, AiImageOperation::TYPE_UPSCALE);

        $this->importMeta = ['operation' => 'upscale', 'model' => 'fal-ai/clarity-upscaler'];

        return $this->import($url, 'ai-edited');
    }

    public function edit(string $sourceUrl, string $prompt, ?string $siteId = null): ?int
    {
        $url = $this->callFal('https://fal.run/fal-ai/nano-banana/edit', [
            'prompt' => $prompt,
            'image_urls' => [$sourceUrl],
            'num_images' => 1,
            'output_format' => 'png',
        ], $siteId, AiImageOperation::TYPE_EDIT);

        $this->importMeta = ['operation' => 'edit', 'model' => 'fal-ai/nano-banana/edit', 'prompt' => $prompt];

        return $this->import($url, 'ai-edited');
    }

    public function productPhoto(string $sourceUrl, ?string $siteId = null): ?int
    {
        $cutout = $this->callFal('https://fal.run/fal-ai/birefnet', [
            'image_url' => $sourceUrl,
        ], $siteId, AiImageOperation::TYPE_PRODUCT_PHOTO);

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
        ], $siteId, AiImageOperation::TYPE_PRODUCT_PHOTO);

        $this->importMeta = ['operation' => 'product_photo', 'model' => 'fal-ai/nano-banana/edit'];

        return $this->import($studio, 'ai-edited');
    }

    public function generate(
        string $prompt,
        string $ratio = '1:1',
        ?string $referenceImageUrl = null,
        ?string $siteId = null,
        array $context = [],
    ): ?int {
        // De operatie van de taak telt als de aanroep zelf, niet als een tweede.
        if ($this->operation !== null && $this->operation->type === AiImageOperation::TYPE_GENERATE) {
            $context += ['operation' => $this->operation, 'throw_on_limit' => $this->throwOnLimit];
        }

        return app(AiImageGenerator::class)->generate(
            prompt: $prompt,
            ratio: $ratio,
            referenceImageUrl: $referenceImageUrl,
            folder: 'ai-generated',
            siteId: $siteId,
            context: $context,
        );
    }

    /** Wat clarity-upscaler standaard doet; de taak geeft geen andere factor mee. */
    public const UPSCALE_FACTOR = 2;

    /**
     * Een melding als een upscale van deze bron groter wordt dan wat de
     * inname van `ai` aanneemt, anders null. Onbekende afmetingen: null
     * (dan beslist de inname achteraf).
     */
    public static function upscaleRefusal(?int $width, ?int $height, int $factor = self::UPSCALE_FACTOR): ?string
    {
        if (! $width || ! $height || $width < 1 || $height < 1) {
            return null;
        }

        $factor = max(1, $factor);
        $max = \Dashed\DashedFiles\Classes\Ingest\IngestPolicy::maxMegapixels('ai');
        $result = ($width * $factor) * ($height * $factor) / 1_000_000;

        if ($result <= $max) {
            return null;
        }

        return __('Deze afbeelding is te groot om te upscalen: het resultaat zou :result megapixel worden, en de bibliotheek neemt hooguit :max megapixel aan. Verklein de afbeelding eerst.', [
            'result' => (int) ceil($result),
            'max' => (int) $max,
        ]);
    }

    /**
     * De afmetingen van een item in de bibliotheek, voor upscaleRefusal().
     *
     * @return array{0: ?int, 1: ?int}|null
     */
    public static function dimensionsOf(?int $mediaId): ?array
    {
        if (! $mediaId) {
            return null;
        }

        return rescue(function () use ($mediaId) {
            $item = app(MediaLibrary::class)->find($mediaId);
            $file = $item?->file();
            $width = $item?->getAttribute('width') ?? $file?->getCustomProperty('width');
            $height = $item?->getAttribute('height') ?? $file?->getCustomProperty('height');

            return is_numeric($width) && is_numeric($height) ? [(int) $width, (int) $height] : null;
        }, null, false);
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
        ?array $context = null,
    ): int {
        // Een beschermd item (privé, chatbijlage, map chat of orders) gaat
        // niet naar Fal: de operatie staat meteen op mislukt, zonder taak.
        if ($sourceMediaId && self::isProtectedSource($sourceMediaId)) {
            return AiImageOperation::create([
                'type' => $type,
                'status' => AiImageOperation::STATUS_FAILED,
                'source_media_id' => $sourceMediaId,
                'params' => $params,
                'batch_id' => $batchId,
                'site_id' => $siteId,
                'context' => $context,
                'error' => __('Deze afbeelding is privé of beschermd en gaat niet naar de AI-dienst.'),
            ])->id;
        }

        $op = AiImageOperation::create([
            'type' => $type,
            'status' => AiImageOperation::STATUS_QUEUED,
            'source_media_id' => $sourceMediaId,
            'params' => $params,
            'batch_id' => $batchId,
            'site_id' => $siteId,
            'context' => $context,
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

    /**
     * Of een item van de bibliotheek niet naar een externe AI mag. Kan de
     * toets niet gedaan worden, dan geldt het als beschermd.
     */
    public static function isProtectedSource(int $mediaId): bool
    {
        return (bool) rescue(function () use ($mediaId) {
            $item = \Dashed\DashedFiles\Models\MediaItem::withoutGlobalScopes()->find($mediaId);

            return $item !== null && \Dashed\DashedFiles\Classes\MediaPrivacy::isProtected($item);
        }, true, false);
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
