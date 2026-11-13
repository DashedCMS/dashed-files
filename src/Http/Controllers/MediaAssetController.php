<?php

namespace Dashed\DashedFiles\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Dashed\DashedFiles\Classes\MediaAssets;

/**
 * Levert de stijl en het script van de mediabibliotheek uit het pakket zelf.
 * De versie in de URL is een hash van de inhoud: klopt hij, dan mag de
 * browser het bestand een jaar bewaren; een oude versie (een pagina van
 * voor een release) gaat zonder cache door naar de huidige.
 */
class MediaAssetController
{
    public function __invoke(string $version, string $file): Response|RedirectResponse
    {
        abort_unless(isset(MediaAssets::FILES[$file]), 404);

        if (! hash_equals(MediaAssets::version(), $version)) {
            return redirect(MediaAssets::url($file), 302)->header('Cache-Control', 'no-store');
        }

        $content = (string) file_get_contents(MediaAssets::path($file));

        return response($content, 200, [
            'Content-Type' => MediaAssets::CONTENT_TYPES[$file],
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
