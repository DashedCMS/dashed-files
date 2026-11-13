<?php

namespace Dashed\DashedFiles\Classes;

/**
 * De iconen van het mediaveld als één svg-sprite per pagina. Een veld met
 * vijf knoppen droeg elk icoon voluit mee (ruim 600 tekens per stuk); nu
 * verwijst het met <use> naar een symbool dat de render hook één keer in
 * de pagina zet. Een icoon buiten de lijst valt terug op het voluit
 * getekende icoon, zodat een nieuwe knop nooit leeg blijft.
 */
final class MediaIcons
{
    /** De iconen die het mediaveld gebruikt. */
    public const NAMES = [
        'heroicon-o-link',
        'heroicon-o-exclamation-triangle',
        'heroicon-m-arrows-right-left',
        'heroicon-m-arrow-top-right-on-square',
        'heroicon-m-trash',
        'heroicon-o-question-mark-circle',
        'heroicon-s-play',
        'heroicon-o-film',
        'heroicon-o-photo',
        'heroicon-o-document-text',
        'heroicon-s-lock-closed',
        'heroicon-m-arrow-uturn-left',
        'heroicon-m-arrow-left',
        'heroicon-m-arrow-right',
        'heroicon-m-pencil-square',
        'heroicon-m-arrow-path',
        'heroicon-m-photo',
    ];

    /** Presentatie-attributen die van de svg op het symbool komen, zodat <use> ze erft. */
    private const COPIED = ['viewBox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'];

    /** @var array<string, array{attributes: string, inner: string}|null> */
    private static array $parsed = [];

    public static function id(string $name): string
    {
        return 'dm-i-' . preg_replace('/^heroicon-/', '', $name);
    }

    /** Het icoon als verwijzing naar het symbool in de sprite. */
    public static function use(string $name, string $class = 'dashed-media-field-icon'): string
    {
        if (self::parse($name) === null) {
            return (string) rescue(fn () => svg($name, $class, ['aria-hidden' => 'true'])->toHtml(), '', false);
        }

        return '<svg class="' . e($class) . '" aria-hidden="true"><use xlink:href="#' . self::id($name) . '"/></svg>';
    }

    /** Alle symbolen in één verborgen svg. */
    public static function sprite(): string
    {
        $symbols = '';

        foreach (self::NAMES as $name) {
            $parsed = self::parse($name);

            if ($parsed !== null) {
                $symbols .= '<symbol id="' . self::id($name) . '"' . $parsed['attributes'] . '>' . $parsed['inner'] . '</symbol>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false" data-dashed-media-sprite>' . $symbols . '</svg>';
    }

    /**
     * @return array{attributes: string, inner: string}|null
     */
    private static function parse(string $name): ?array
    {
        if (array_key_exists($name, self::$parsed)) {
            return self::$parsed[$name];
        }

        if (! in_array($name, self::NAMES, true)) {
            return self::$parsed[$name] = null;
        }

        $html = (string) rescue(fn () => svg($name)->toHtml(), '', false);

        if (! preg_match('/^\s*<svg\b([^>]*)>(.*)<\/svg>\s*$/s', $html, $match)) {
            return self::$parsed[$name] = null;
        }

        $attributes = '';

        foreach (self::COPIED as $attribute) {
            if (preg_match('/\s' . preg_quote($attribute, '/') . '="([^"]*)"/', $match[1], $value)) {
                $attributes .= ' ' . $attribute . '="' . $value[1] . '"';
            }
        }

        return self::$parsed[$name] = ['attributes' => $attributes, 'inner' => preg_replace('/\s*\n\s*/', '', trim($match[2]))];
    }
}
