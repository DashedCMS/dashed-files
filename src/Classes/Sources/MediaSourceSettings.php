<?php

namespace Dashed\DashedFiles\Classes\Sources;

use Throwable;
use Illuminate\Support\Facades\Crypt;
use Dashed\DashedCore\Models\Customsetting;

/**
 * De instellingen van de externe bronnen, per site. Een sleutel van een
 * aanbieder staat versleuteld in de instellingentabel en komt alleen hier
 * weer in klare tekst: het instellingenscherm toont hem nooit terug.
 *
 * Bewust geen gedeelde sleutel in .env: de voorwaarden van Pexels en Pixabay
 * zeggen niets over één sleutel voor meerdere klantsites, en de limieten
 * tellen per sleutel. Elke site vult zijn eigen sleutel in.
 */
final class MediaSourceSettings
{
    public const DEFAULT_STOCK_MAX_WIDTH = 2500;

    public const DEFAULT_AI_DAILY_LIMIT = 25;

    public const DEFAULT_AI_EDIT_DAILY_LIMIT = 100;

    /** Wat standaard aanstaat zonder dat iemand iets instelde. Stock staat uit tot een beheerder hem bewust aanzet. */
    private const DEFAULT_ENABLED = ['url' => true, 'ai' => true];

    public static function settingName(string $source, string $what): string
    {
        return 'media_source_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($source)) . '_' . $what;
    }

    public static function enabled(string $source, ?string $siteId = null): bool
    {
        $value = self::read(self::settingName($source, 'enabled'), $siteId);

        if ($value === null || $value === '') {
            return self::DEFAULT_ENABLED[$source] ?? false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function setEnabled(string $source, bool $enabled, ?string $siteId = null): void
    {
        Customsetting::set(self::settingName($source, 'enabled'), $enabled ? '1' : '0', $siteId);
    }

    public static function hasKey(string $source, ?string $siteId = null): bool
    {
        return self::key($source, $siteId) !== null;
    }

    /** De sleutel in klare tekst, alleen voor de aanroep naar de aanbieder. */
    public static function key(string $source, ?string $siteId = null): ?string
    {
        $stored = self::read(self::settingName($source, 'key'), $siteId);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $key = trim(Crypt::decryptString($stored));
        } catch (Throwable) {
            // Een andere APP_KEY of een met de hand gezette waarde: geen sleutel.
            return null;
        }

        return $key === '' ? null : $key;
    }

    public static function setKey(string $source, ?string $key, ?string $siteId = null): void
    {
        $key = trim((string) $key);

        Customsetting::set(self::settingName($source, 'key'), $key === '' ? null : Crypt::encryptString($key), $siteId);
    }

    public static function stockMaxWidth(?string $siteId = null): int
    {
        $value = self::read('media_source_stock_max_width', $siteId);

        return is_numeric($value) ? max(800, min(6000, (int) $value)) : self::DEFAULT_STOCK_MAX_WIDTH;
    }

    public static function setStockMaxWidth(int $width, ?string $siteId = null): void
    {
        Customsetting::set('media_source_stock_max_width', (string) max(800, min(6000, $width)), $siteId);
    }

    /** Het aantal AI-afbeeldingen dat een site per dag mag laten maken. 0 = geen. */
    public static function aiDailyLimit(?string $siteId = null): int
    {
        $value = self::read('media_source_ai_daily_limit', $siteId);

        return is_numeric($value) ? max(0, min(1000, (int) $value)) : self::DEFAULT_AI_DAILY_LIMIT;
    }

    public static function setAiDailyLimit(int $limit, ?string $siteId = null): void
    {
        Customsetting::set('media_source_ai_daily_limit', (string) max(0, min(1000, $limit)), $siteId);
    }

    /**
     * Het aantal AI-bewerkingen (upscalen, achtergrond weghalen,
     * retoucheren, productfoto) dat een site per dag mag laten doen. Elke
     * betaalde aanroep telt; een productfoto zijn er twee. 0 = geen.
     */
    public static function aiEditDailyLimit(?string $siteId = null): int
    {
        $value = self::read('media_source_ai_edit_daily_limit', $siteId);

        return is_numeric($value) ? max(0, min(5000, (int) $value)) : self::DEFAULT_AI_EDIT_DAILY_LIMIT;
    }

    public static function setAiEditDailyLimit(int $limit, ?string $siteId = null): void
    {
        Customsetting::set('media_source_ai_edit_daily_limit', (string) max(0, min(5000, $limit)), $siteId);
    }

    private static function read(string $name, ?string $siteId): mixed
    {
        return rescue(fn () => Customsetting::get($name, $siteId), null, false);
    }
}
