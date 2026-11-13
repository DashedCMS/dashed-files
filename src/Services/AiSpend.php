<?php

namespace Dashed\DashedFiles\Services;

use Throwable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedFiles\Models\AiImageOperation;
use Dashed\DashedFiles\Classes\Sources\MediaSourceSettings;

/**
 * Het ene punt waar geld wordt uitgegeven aan de AI-dienst voor beelden.
 * Elke betaalde aanroep, via welke ingang ook (de bron `ai` in het venster,
 * de oude hint-actie, AiImageOperations, de wachtrij van dashed-core), komt
 * hier langs vlak voordat hij de deur uit gaat. Toetsen en tellen gebeuren
 * onder één slot per site en soort, zodat twee gelijktijdige aanroepen niet
 * allebei net onder het plafond blijven.
 *
 * Wat er telt: elke operatie met een `charged_at` in de context (een
 * aanroep die verstuurd is, ook als hij daarna mislukte), plus voor
 * genereren de aanvragen uit het venster die nog in de wachtrij staan: die
 * hebben hun plek al bij het aanvragen gekregen.
 *
 * Twee plafonds per site per dag: genereren (`ai_daily_limit`) en bewerken
 * (upscale, achtergrond, retoucheren, productfoto: `ai_edit_daily_limit`).
 * 0 betekent dat er niets mag.
 */
final class AiSpend
{
    public const KIND_GENERATE = 'generate';

    public const KIND_EDIT = 'edit';

    public const ORIGIN_CHARGE = 'charge';

    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT = 5;

    /**
     * Toetst het plafond en legt de aanroep vast. Met $claim telt die
     * operatie zelf als de aanroep (één keer); anders, of als hij al eerder
     * betaald is, komt er een eigen rij bij.
     *
     * @param  array<string, mixed>  $details  type, params en context voor een nieuwe rij
     *
     * @throws AiCeilingReached
     */
    public static function charge(string $kind, ?string $siteId, ?AiImageOperation $claim = null, array $details = []): AiImageOperation
    {
        $siteId = self::site($siteId);

        try {
            return Cache::lock(self::lockKey($kind, $siteId), self::LOCK_SECONDS)->block(self::LOCK_WAIT, function () use ($kind, $siteId, $claim, $details) {
                $limit = self::limit($kind, $siteId);

                if (self::usedToday($kind, $siteId, $claim?->getKey()) >= $limit) {
                    throw new AiCeilingReached(self::refusal($kind, $limit));
                }

                $now = Carbon::now()->toIso8601String();

                if ($claim !== null && $claim->exists && empty(($claim->context ?? [])['charged_at'])) {
                    $claim->update(['context' => array_merge((array) $claim->context, ['charged_at' => $now])]);

                    return $claim;
                }

                return AiImageOperation::create([
                    'type' => (string) ($details['type'] ?? ($kind === self::KIND_GENERATE ? AiImageOperation::TYPE_GENERATE : AiImageOperation::TYPE_EDIT)),
                    'status' => AiImageOperation::STATUS_PROCESSING,
                    'params' => (array) ($details['params'] ?? []),
                    'site_id' => $siteId,
                    'context' => array_merge((array) ($details['context'] ?? []), ['origin' => self::ORIGIN_CHARGE, 'charged_at' => $now]),
                ]);
            });
        } catch (AiCeilingReached $exception) {
            throw $exception;
        } catch (Throwable) {
            // Geen slot of geen tabel: bij twijfel gaat er niets de deur uit.
            throw new AiCeilingReached(__('De AI-dienst is nu even niet beschikbaar. Probeer het zo opnieuw.'));
        }
    }

    /** Werkt de uitkomst bij op een eigen rij van charge(). Een geclaimde operatie beheert haar aanroeper zelf. */
    public static function settle(AiImageOperation $charge, ?int $resultId): void
    {
        if (($charge->context['origin'] ?? null) !== self::ORIGIN_CHARGE) {
            return;
        }

        rescue(fn () => $charge->update($resultId
            ? ['status' => AiImageOperation::STATUS_DONE, 'result_media_id' => $resultId]
            : ['status' => AiImageOperation::STATUS_FAILED, 'error' => 'failed']), null, false);
    }

    public static function usedToday(string $kind, ?string $siteId, mixed $except = null): int
    {
        $siteId = self::site($siteId);
        $staleAfter = Carbon::now()->subSeconds(self::staleSeconds());

        return AiImageOperation::query()
            ->whereIn('type', self::types($kind))
            ->when($siteId === null, fn ($query) => $query->whereNull('site_id'), fn ($query) => $query->where('site_id', $siteId))
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->where(function ($query) use ($kind, $staleAfter) {
                $query->whereNotNull('context->charged_at');

                if ($kind === self::KIND_GENERATE) {
                    // Een aanvraag uit het venster die nog wacht heeft zijn plek al; een die is blijven hangen niet meer.
                    $query->orWhere(fn ($reserved) => $reserved
                        ->where('status', AiImageOperation::STATUS_QUEUED)
                        ->where('context->origin', \Dashed\DashedFiles\Classes\Sources\AiSource::ORIGIN)
                        ->whereNull('context->charged_at')
                        ->where('updated_at', '>=', $staleAfter));
                }
            })
            ->count();
    }

    public static function limit(string $kind, ?string $siteId): int
    {
        return $kind === self::KIND_GENERATE
            ? MediaSourceSettings::aiDailyLimit($siteId)
            : MediaSourceSettings::aiEditDailyLimit($siteId);
    }

    /**
     * Voert $callback uit onder het slot van het plafond, voor een aanvraag
     * die zijn plek reserveert (de bron `ai` in het venster).
     *
     * @template T
     *
     * @param  \Closure(int $used, int $limit): T  $callback
     * @return T
     *
     * @throws AiCeilingReached
     */
    public static function reserve(string $kind, ?string $siteId, \Closure $callback): mixed
    {
        $siteId = self::site($siteId);
        try {
            return Cache::lock(self::lockKey($kind, $siteId), self::LOCK_SECONDS)->block(self::LOCK_WAIT, function () use ($kind, $siteId, $callback) {
                $limit = self::limit($kind, $siteId);
                $used = self::usedToday($kind, $siteId);

                if ($used >= $limit) {
                    throw new AiCeilingReached(self::refusal($kind, $limit));
                }

                return $callback($used, $limit);
            });
        } catch (AiCeilingReached $exception) {
            throw $exception;
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw new AiCeilingReached(__('Er worden nu veel afbeeldingen tegelijk aangevraagd. Probeer het zo opnieuw.'));
        }
    }

    public static function staleSeconds(): int
    {
        $value = config('dashed-files.source_limits.ai_stale_seconds');

        return is_numeric($value) && (int) $value >= 60 ? (int) $value : 600;
    }

    /**
     * @return array<int, string>
     */
    public static function types(string $kind): array
    {
        return $kind === self::KIND_GENERATE
            ? [AiImageOperation::TYPE_GENERATE]
            : [AiImageOperation::TYPE_REMOVE_BACKGROUND, AiImageOperation::TYPE_UPSCALE, AiImageOperation::TYPE_EDIT, AiImageOperation::TYPE_PRODUCT_PHOTO];
    }

    public static function refusal(string $kind, int $limit): string
    {
        return $kind === self::KIND_GENERATE
            ? __('Het dagplafond van :max AI-afbeeldingen voor deze site is bereikt. Morgen kan het weer, of een beheerder verhoogt het bij Instellingen, Media.', ['max' => $limit])
            : __('Het dagplafond van :max AI-bewerkingen voor deze site is bereikt. Morgen kan het weer, of een beheerder verhoogt het bij Instellingen, Media.', ['max' => $limit]);
    }

    /**
     * Een aanroep zonder site (de oude hint-actie, de wachtrij van
     * dashed-core) telt voor de actieve site: daar leest Customsetting ook
     * het plafond, en een eigen emmer zonder site zou een tweede plafond zijn.
     */
    private static function site(?string $siteId): ?string
    {
        return $siteId ?? rescue(fn () => \Dashed\DashedFiles\Classes\Sources\MediaSourceRegistry::siteId(), null, false);
    }

    private static function lockKey(string $kind, ?string $siteId): string
    {
        return 'media-ai-spend:' . $kind . ':' . ($siteId ?? '-');
    }
}
