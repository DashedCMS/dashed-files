<?php

namespace Dashed\DashedFiles\Classes\Sources;

use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Classes\MediaPrivacy;
use Dashed\DashedFiles\Jobs\GenerateAiMedia;
use Dashed\DashedFiles\Models\AiImageOperation;
use Dashed\DashedFiles\Services\AiSpend;
use Dashed\DashedFiles\Services\AiCeilingReached;
use Dashed\DashedFiles\Services\AiImageGenerator;
use Dashed\DashedFiles\Classes\Ingest\IngestPolicy;

/**
 * Een afbeelding laten maken door AI. De ene ingang voor wat eerder de
 * hint-actie op een mediaveld en de generate-actie in de bibliotheek waren.
 * De aanroep zelf loopt in de wachtrij (GenerateAiMedia); dit verzoek zet
 * alleen een aanvraag klaar en geeft een kenmerk terug waarmee het venster
 * de stand opvraagt.
 *
 * Elke aanvraag kost geld. Daarom een plafond per site per dag, bovenop de
 * limiet per gebruiker in het venster.
 */
class AiSource implements MediaSource
{
    public const KEY = 'ai';

    public const ORIGIN = 'media-source';

    public const PROMPT_MAX = 2000;

    public const RATIOS = ['1:1', '4:5', '9:16', '2:3', '3:4', '4:3', '16:9'];

    /** Na zoveel seconden zonder uitkomst geldt een aanvraag als mislukt (de werker viel weg). Instelbaar: dashed-files.source_limits.ai_stale_seconds. */
    public const STALE_SECONDS = 600;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('AI');
    }

    public function icon(): string
    {
        return 'heroicon-o-sparkles';
    }

    public function kind(): string
    {
        return self::KIND_GENERATE;
    }

    /** Zonder sleutel of zonder het AI-pakket (dat elke aanroep in het AI-logboek zet) bestaat de bron niet. */
    public function isAvailable(?string $siteId = null): bool
    {
        return MediaSourceSettings::enabled(self::KEY, $siteId)
            && class_exists(\Dashed\DashedAi\Logging\FalRequest::class)
            && AiImageGenerator::isConfigured($siteId);
    }

    public function browserConfig(?string $siteId = null): array
    {
        return [
            'title' => __('Afbeelding maken met AI'),
            'placeholder' => __('Bijv. een minimalistische productfoto van een leren tas op een marmeren tafel, zacht daglicht.'),
            'button' => __('Genereren'),
            'help' => __('Het maken duurt meestal een halve minuut en gebeurt op de achtergrond. Het resultaat komt in de map waar je nu staat. Elke afbeelding kost tegoed bij de AI-dienst.'),
            'ratios' => array_map(fn (string $ratio) => ['value' => $ratio, 'label' => $ratio], self::RATIOS),
            'referenceHelp' => __('Met een referentie blijft het product op de foto gelijk en verandert alleen de omgeving. Open eerst een afbeelding in de bibliotheek en klik dan op de knop.'),
            'dailyLimit' => MediaSourceSettings::aiDailyLimit($siteId),
        ];
    }

    public function diagnostics(?string $siteId = null): array
    {
        return [
            'enabled' => MediaSourceSettings::enabled(self::KEY, $siteId),
            'package' => class_exists(\Dashed\DashedAi\Logging\FalRequest::class),
            'key' => AiImageGenerator::isConfigured($siteId),
            'daily_limit' => MediaSourceSettings::aiDailyLimit($siteId),
            'used_today' => rescue(fn () => $this->usedToday($siteId), null, false),
        ];
    }

    /**
     * Zet een aanvraag klaar en geeft het kenmerk terug.
     *
     * @throws MediaSourceException
     */
    public function request(string $prompt, string $ratio, ?int $referenceItemId, ?int $folderId, int $userId, ?string $siteId = null): string
    {
        $prompt = trim((string) preg_replace('/[^\P{C}\n]+/u', ' ', mb_scrub($prompt, 'UTF-8')));

        if ($prompt === '') {
            throw new MediaSourceException(__('Beschrijf eerst wat je wilt zien.'), 'invalid');
        }

        if (mb_strlen($prompt) > self::PROMPT_MAX) {
            throw new MediaSourceException(__('De beschrijving mag maximaal :max tekens zijn.', ['max' => self::PROMPT_MAX]), 'invalid');
        }

        $ratio = in_array($ratio, self::RATIOS, true) ? $ratio : '1:1';

        if ($referenceItemId !== null) {
            $this->referenceUrl($referenceItemId);
        }

        $request = (string) Str::uuid();

        // Toetsen en de plek innemen onder hetzelfde slot als waar de taak
        // later betaalt (AiSpend): twee gelijktijdige aanvragen blijven niet
        // allebei net onder het plafond.
        try {
            AiSpend::reserve(AiSpend::KIND_GENERATE, $siteId, fn () => AiImageOperation::create([
                'type' => AiImageOperation::TYPE_GENERATE,
                'status' => AiImageOperation::STATUS_QUEUED,
                'params' => ['prompt' => $prompt, 'ratio' => $ratio, 'reference_item_id' => $referenceItemId, 'folder_id' => $folderId],
                'batch_id' => $request,
                'site_id' => $siteId,
                'context' => ['origin' => self::ORIGIN, 'user_id' => $userId],
            ]));
        } catch (AiCeilingReached $exception) {
            throw new MediaSourceException($exception->getMessage(), 'limit');
        }

        GenerateAiMedia::dispatch($request);

        return $request;
    }

    /**
     * De stand van een aanvraag, alleen voor wie hem deed. Een onbekend
     * kenmerk en dat van een ander geven hetzelfde antwoord: null.
     *
     * @return array{status: string, item_id?: int, error?: string}|null
     */
    public function status(string $request, int $userId): ?array
    {
        $operation = Str::isUuid($request) ? self::operation($request) : null;

        if (! $operation || (int) ($operation->context['user_id'] ?? 0) !== $userId) {
            return null;
        }

        if ($operation->status === AiImageOperation::STATUS_DONE && $operation->result_media_id) {
            return ['status' => 'done', 'item_id' => (int) $operation->result_media_id];
        }

        $stale = ! $operation->isFinished() && $operation->updated_at && $operation->updated_at->lt(Carbon::now()->subSeconds(AiSpend::staleSeconds()));

        if ($stale) {
            // Vastgelegd, en alleen als hij nog niet klaar is: een taak die
            // hierna nog wordt opgepakt, ziet dat hij verlopen is en betaalt niet.
            AiImageOperation::query()
                ->whereKey($operation->getKey())
                ->whereIn('status', [AiImageOperation::STATUS_QUEUED, AiImageOperation::STATUS_PROCESSING])
                ->update(['status' => AiImageOperation::STATUS_FAILED, 'error' => 'expired']);
        }

        if ($operation->status === AiImageOperation::STATUS_FAILED || $operation->status === AiImageOperation::STATUS_DONE || $stale) {
            return ['status' => 'failed', 'error' => self::failureMessage((string) $operation->error)];
        }

        return ['status' => $operation->status === AiImageOperation::STATUS_PROCESSING ? 'processing' : 'queued'];
    }

    public static function operation(string $request): ?AiImageOperation
    {
        return AiImageOperation::query()
            ->where('batch_id', $request)
            ->where('type', AiImageOperation::TYPE_GENERATE)
            ->get()
            ->first(fn (AiImageOperation $operation) => ($operation->context['origin'] ?? null) === self::ORIGIN);
    }

    /**
     * Het adres van een referentiebeeld voor de AI-dienst. Privé en
     * beschermde bestanden (chat, bestellingen) gaan nooit naar buiten; dat
     * wordt bij de aanvraag getoetst en vlak voor het versturen nog eens.
     *
     * @throws MediaSourceException
     */
    public function referenceUrl(int $itemId): string
    {
        $item = app(MediaLibrary::class)->find($itemId);
        $file = $item?->file();

        if (! $item instanceof MediaItem || ! $file) {
            throw new MediaSourceException(__('De referentieafbeelding bestaat niet meer.'), 'reference');
        }

        if (MediaPrivacy::isProtected($item) || \Dashed\DashedFiles\Classes\Access\MediaStorage::isPrivateDisk($file->disk)) {
            throw new MediaSourceException(__('Deze afbeelding is privé of beschermd en kan niet als referentie naar de AI-dienst.'), 'reference');
        }

        if (! IngestPolicy::isRaster(strtolower((string) $file->mime_type))) {
            throw new MediaSourceException(__('Alleen een foto (jpg, png, webp) kan als referentie dienen.'), 'reference');
        }

        $url = (string) rescue(fn () => $file->getFullUrl(), '', false);

        if (! preg_match('#^https?://#i', $url)) {
            throw new MediaSourceException(__('Deze afbeelding heeft geen openbaar adres en kan niet als referentie dienen.'), 'reference');
        }

        return $url;
    }

    /** Wat er vandaag voor deze site aan afbeeldingen is betaald of nog in de wachtrij staat, via welke ingang ook. */
    public function usedToday(?string $siteId): int
    {
        return AiSpend::usedToday(AiSpend::KIND_GENERATE, $siteId);
    }

    /** De melding voor de beheerder: nooit de ruwe fout van de dienst. */
    public static function failureMessage(string $code): string
    {
        return match ($code) {
            'reference' => __('De referentieafbeelding kon niet worden gebruikt: hij is privé, beschermd of bestaat niet meer.'),
            'unavailable' => __('Afbeeldingen maken met AI staat niet meer aan.'),
            'limit' => __('Het dagplafond voor AI-afbeeldingen van deze site is bereikt. Morgen kan het weer.'),
            'time' => __('Deze server geeft een verzoek te weinig tijd om een afbeelding te laten maken. Vraag de beheerder om een wachtrij in te stellen.'),
            default => __('De afbeelding kon niet worden gemaakt. Probeer het opnieuw of pas de beschrijving aan.'),
        };
    }
}
