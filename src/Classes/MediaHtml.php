<?php

namespace Dashed\DashedFiles\Classes;

/**
 * Haalt de inspringing en lege regels uit de uitvoer van een Blade-view.
 * Elke regelovergang blijft als één regelovergang staan: voor de browser is
 * dat dezelfde witruimte als eerst (ook tussen twee knoppen naast elkaar),
 * alleen zonder de tientallen spaties ervoor.
 */
final class MediaHtml
{
    public static function compact(string $html): string
    {
        // Eerst de regelovergangen, dan de spaties tussen twee tags
        // (">    <"): alleen tussen tags, nooit in tekst of in een attribuutwaarde.
        $html = (string) preg_replace('/[ \t]*\n\s*/', "\n", $html);

        return (string) preg_replace('/>[ \t]{2,}</', '> <', $html);
    }
}
