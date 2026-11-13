<?php

namespace Dashed\DashedFiles\Listeners;

use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Illuminate\Contracts\Filesystem\Filesystem;
use Dashed\DashedFiles\Classes\Svg\SvgSanitizer;
use Dashed\DashedFiles\Classes\Ingest\MediaChecksums;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

/**
 * Vangnet zolang de kiezer en de bibliotheekpagina van het oude pakket nog
 * zelf bestanden aanmaken, buiten MediaLibrary::ingest() om. Dit event vuurt
 * nadat het bestand op de schijf staat; de observer op de mediarij is
 * daarvoor te vroeg.
 */
class GuardMediaAddedOutsideIngest
{
    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $media = $event->media;
        $item = MediaItem::fromMedia($media);

        if (! $item) {
            return;
        }

        // De voorvertoning van een video of pdf is niet het bestand van het
        // item: zijn grootte hoort niet op het item, en hij komt altijd uit
        // onze eigen code.
        if ($media->collection_name === MediaItem::COLLECTION_PREVIEW) {
            return;
        }

        if ($this->isSvg($media)) {
            // Wat via ingest() kwam is al opgeschoond en draagt de vlag.
            if (! $media->getCustomProperty('svg_sanitized')) {
                $this->sanitize($media, $item);
            }

            return;
        }

        $checksums = app(MediaChecksums::class);

        // Alleen de grootte: of dit bestand gehasht moet worden hangt af van
        // of een ander bestand even groot is, en dat zoekt de taak zelf uit.
        if ($checksums->available() && $item->getAttribute('checksum') === null && $item->getAttribute('size') === null) {
            MediaItem::withoutGlobalScopes()->toBase()->where('id', $item->getKey())->update(['size' => (int) $media->size]);
        }
    }

    private function isSvg(Media $media): bool
    {
        return str_contains(strtolower((string) $media->mime_type), 'svg')
            || strtolower(pathinfo((string) $media->file_name, PATHINFO_EXTENSION)) === 'svg';
    }

    private function sanitize(Media $media, MediaItem $item): void
    {
        $sanitizer = app(SvgSanitizer::class);

        try {
            $disk = Storage::disk($media->disk);
            $path = $media->getPathRelativeToRoot();
            $clean = $sanitizer->sanitize((string) $disk->get($path), 'file');

            if ($clean !== null) {
                // Het type mee, anders raadt een objectopslag het uit de
                // inhoud, en de zichtbaarheid die het bestand al had.
                $disk->put($path, $clean, ['ContentType' => 'image/svg+xml', 'visibility' => $this->visibility($disk, $path)]);
            }
        } catch (Throwable $exception) {
            // Niet te lezen of niet terug te schrijven is hetzelfde als niet
            // op te schonen: het onbewerkte bestand mag niet blijven staan.
            report($exception);
            $clean = null;
        }

        if ($clean === null) {
            Log::warning('Media: svg buiten de inname om toegevoegd en niet op te schonen, item verwijderd', [
                'item' => $item->getKey(),
                'file' => $media->file_name,
            ]);

            // Het item verwijderen haalt ook de mediarij en het bestand weg.
            // Definitief: in de prullenbak zou de site het bestand blijven tonen.
            $item->forceDelete();

            $this->notify((string) $media->file_name);

            return;
        }

        $media->setCustomProperty('svg_sanitized', SvgSanitizer::VERSION);

        if ($dimensions = $sanitizer->dimensions($clean)) {
            $media->setCustomProperty('original_width', $dimensions['width']);
            $media->setCustomProperty('original_height', $dimensions['height']);
        }

        $media->mime_type = 'image/svg+xml';
        $media->size = strlen($clean);
        $media->saveQuietly();

        if (app(MediaChecksums::class)->available()) {
            MediaItem::withoutGlobalScopes()->toBase()->where('id', $item->getKey())->update([
                'size' => strlen($clean),
                'checksum' => hash('sha256', $clean),
            ]);
        }

        app(MediaLibrary::class)->invalidate($item, 'sanitized');
    }

    private function visibility(Filesystem $disk, string $path): string
    {
        try {
            return $disk->getVisibility($path) === 'private' ? 'private' : 'public';
        } catch (Throwable) {
            // Niet vast te stellen: de strengste, het bestand is op dat moment hoe dan ook nog niet opgeschoond geweest.
            return 'private';
        }
    }

    /**
     * De upload leek voor de beheerder gelukt; zonder melding is het bestand
     * daarna gewoon verdwenen.
     */
    private function notify(string $fileName): void
    {
        $notification = 'Filament\\Notifications\\Notification';

        if (! class_exists($notification) || ! auth()->check()) {
            return;
        }

        rescue(fn () => $notification::make()
            ->danger()
            ->title(__('Svg niet toegevoegd'))
            ->body(__('Het bestand :naam bevat onderdelen die niet zijn toegestaan en kon niet veilig worden opgeschoond. Het is verwijderd.', ['naam' => $fileName]))
            ->send());
    }
}
