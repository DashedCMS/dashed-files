<?php

namespace Dashed\DashedFiles\Classes\Access;

/**
 * De veldinstellingen die een mediaveld via de browser aan het keuzevenster
 * doorgeeft (toegestane bronnen, privé, sites, types, meervoud, maximum),
 * ondertekend door de server. De browser kan ze lezen maar niet wijzigen:
 * het venster toetst de handtekening bij openPicker() en valt zonder
 * geldige handtekening terug op de strengste stand (geen externe bronnen,
 * geen privé, geen sitefilter).
 *
 * De handtekening gaat over de opgeschoonde vorm, zodat veld en venster
 * dezelfde waarden op dezelfde manier lezen, ongeacht hoe het script ze
 * doorgeeft (false of een lege lijst, null of ontbrekend).
 */
final class PickerSignature
{
    public const FIELD = 'signature';

    /**
     * @param  array<string, mixed>  $config
     */
    public static function sign(array $config): string
    {
        return hash_hmac('sha256', (string) json_encode(self::normalize($config)), self::key());
    }

    /**
     * @param  array<string, mixed>  $config  wat de browser stuurde, met `signature`
     */
    public static function verify(array $config): bool
    {
        $given = $config[self::FIELD] ?? null;

        return is_string($given) && $given !== '' && hash_equals(self::sign($config), $given);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function normalize(array $config): array
    {
        $sources = $config['sources'] ?? null;
        $sources = $sources === false ? [] : (is_array($sources)
            ? array_values(array_map('strval', array_filter($sources, 'is_string')))
            : null);

        $list = fn ($value) => array_values(array_map('strval', array_filter(is_array($value) ? $value : [], fn ($entry) => is_string($entry) || is_int($entry))));
        $max = $config['max'] ?? null;

        return [
            'sources' => $sources,
            'allowPrivate' => (bool) ($config['allowPrivate'] ?? false),
            'sites' => $list($config['sites'] ?? null),
            'accept' => $list($config['accept'] ?? null),
            'multiple' => (bool) ($config['multiple'] ?? false),
            'max' => is_numeric($max) && (int) $max > 0 ? (int) $max : null,
        ];
    }

    private static function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7));
        }

        // Een afgeleide sleutel: dezelfde app-sleutel tekent elders andere dingen.
        return hash_hmac('sha256', 'dashed-files:media-picker-config', $key);
    }
}
