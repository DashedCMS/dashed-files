<?php

namespace Dashed\DashedFiles\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Levert een zip uit die de mediabibliotheek net heeft klaargezet. De link
 * is ondertekend, vijf minuten geldig en alleen voor wie hem aanvroeg; het
 * bestand verdwijnt na het versturen. Een link die nooit wordt opgehaald
 * ruimt dashed:clear-temp-images op.
 */
class MediaZipController
{
    public const ROUTE = 'media.zip';

    public const VALID_MINUTES = 5;

    public function __invoke(Request $request, string $token): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);
        abort_unless((string) $request->query('user') === (string) auth()->id(), 403);
        // Het recht kan sinds het klaarzetten zijn ingetrokken.
        abort_unless(\Dashed\DashedFiles\Classes\MediaLibrary::usesOwnEngine() && \Dashed\DashedFiles\Classes\MediaLibrary::allows('view'), 403);

        $path = storage_path('app/media-ingest') . DIRECTORY_SEPARATOR . $token;

        abort_unless(is_file($path), 404);

        return response()->streamDownload(function () use ($path) {
            try {
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, 'media-' . now()->format('Ymd-His') . '.zip', [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) filesize($path),
        ]);
    }
}
