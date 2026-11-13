<?php

namespace Dashed\DashedFiles\Classes;

/**
 * Houdt per verzoek bij of de gedeelde stijl en het script van de
 * mediabibliotheek al in de pagina staan. De beheerpagina brengt ze zelf
 * mee; de render hook aan het eind van de body zet ze er op elke andere
 * pagina bij, en slaat ze over als ze er al staan. Blades @once kan dat
 * niet: dat geldt per render en de pagina en de hook zijn er twee.
 */
final class MediaAssets
{
    private const ATTRIBUTE = 'dashed-files.media-assets';

    public static function markRendered(): void
    {
        if (app()->bound('request')) {
            request()->attributes->set(self::ATTRIBUTE, true);
        }
    }

    public static function rendered(): bool
    {
        return app()->bound('request') && request()->attributes->get(self::ATTRIBUTE) === true;
    }
}
