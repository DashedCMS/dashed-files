<?php

namespace Dashed\DashedFiles\Classes\Preview;

use Throwable;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Jobs\GenerateMediaPreview;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Ingest\IngestPolicy;
use Dashed\DashedFiles\Classes\Ingest\IncomingMedia;
use Dashed\DashedFiles\Classes\MediaPickerFileCache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Dashed\DashedFiles\Classes\Browse\MediaItemPayload;
use Dashed\DashedFiles\Contracts\MediaPreviewGenerator;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;

/**
 * De voorvertoning van een video (poster) of pdf (miniatuur) en wat er van
 * zo'n bestand bekend is (duur, afmetingen, aantal pagina's).
 *
 * De automatische voorvertoning is een jpeg in de collectie `preview` op
 * hetzelfde item, hooguit één. Een beheerder kan in plaats daarvan een
 * afbeelding uit de bibliotheek kiezen (`poster_item_id`). Lezen: eerst de
 * gekozen afbeelding (als dat item bestaat en een afbeelding is), anders de
 * collectie `preview`, anders niets.
 */
class MediaPreviews
{
    public const VIDEO = 'video';

    public const PDF = 'pdf';

    /** De containers waar Mp4Probe de kop van kan lezen. */
    public const MP4_FAMILY = ['video/mp4', 'video/quicktime', 'video/x-m4v', 'video/3gpp'];

    // Een naam die geen gewone upload draagt: wie een mediarij op bestandsnaam zoekt (de SEO-fixer) komt zo niet bij een voorvertoning uit.
    public const POSTER_FILE_NAME = 'dashed-preview.jpg';

    /** `video`, `pdf` of null: van al het andere wordt geen voorvertoning gemaakt. */
    public static function kindOf(?string $mime): ?string
    {
        $mime = strtolower((string) $mime);

        return match (true) {
            str_starts_with($mime, 'video/') => self::VIDEO,
            $mime === 'application/pdf' => self::PDF,
            default => null,
        };
    }

    /**
     * @return array<int, MediaPreviewGenerator>
     */
    public function generators(): array
    {
        $classes = (array) config('dashed-files.preview.generators', [FfmpegPreview::class, GhostscriptPreview::class]);
        $generators = [];

        foreach ($classes as $class) {
            $generator = is_string($class) && class_exists($class) ? rescue(fn () => app($class), null, false) : null;

            if ($generator instanceof MediaPreviewGenerator) {
                $generators[] = $generator;
            }
        }

        return $generators;
    }

    /**
     * De generator die dit type hier echt kan verwerken, of null. Dit zegt
     * alleen wat de server kan; of een bepaald bestand naar een programma
     * mag beslist generatorForFile().
     */
    public function generatorFor(?string $mime): ?MediaPreviewGenerator
    {
        foreach ($this->generators() as $generator) {
            if ($generator->supports((string) $mime) && $generator->isAvailable()) {
                return $generator;
            }
        }

        return null;
    }

    /**
     * De generator voor DIT bestand. Een bestand uit een onbetrouwbare bron
     * (de chat, de API) of zonder bekende bron gaat nooit naar een extern
     * programma: daarvoor is er geen generator, wat de server ook kan. Hier
     * afgedwongen en niet bij het klaarzetten, zodat de taak, het commando
     * en "Poster opnieuw maken" er geen van alle omheen kunnen.
     */
    public function generatorForFile(MediaItem $item, ?Media $file, ?string $mime = null): ?MediaPreviewGenerator
    {
        if (! $file || ! self::isTrustedSource($item, $file)) {
            return null;
        }

        return $this->generatorFor($mime ?? $file->mime_type);
    }

    /** Of de bron van het huidige bestand bekend en betrouwbaar is. Geen bron telt als onbetrouwbaar. */
    public static function isTrustedSource(MediaItem $item, ?Media $file): bool
    {
        $source = (string) ($file?->getCustomProperty('source') ?: $item->getAttribute('source'));

        return $source !== '' && ! IngestPolicy::isUntrustedSource($source);
    }

    /** Of er een werker is: met een synchrone wachtrij draait een taak in het proces dat hem klaarzet. */
    public static function queueIsSync(): bool
    {
        $connection = (string) config('queue.default');

        return $connection === 'sync' || config('queue.connections.' . $connection . '.driver') === 'sync';
    }

    /**
     * Of de server bij het uploaden zelf een poster van een video maakt; zo
     * niet, dan doet de browser het. Met een synchrone wachtrij start er
     * bij een upload geen ffmpeg (dat zou in het webproces draaien), dus
     * dan is het antwoord nee, ook als ffmpeg er is.
     */
    public function serverMakesVideoPosters(): bool
    {
        return ! self::queueIsSync() && $this->generatorFor('video/mp4') !== null;
    }

    /** Wat de uploadinterface te horen krijgt: of de browser zelf een poster van een video moet maken. */
    public static function browserMakesPosters(): bool
    {
        return (bool) rescue(fn () => self::available() && ! app(self::class)->serverMakesVideoPosters(), false, false);
    }

    public static function available(): bool
    {
        return MediaLibrary::usesOwnEngine() && BrowseSchema::hasPreview();
    }

    /**
     * Zet de taak voor dit item klaar. Met een synchrone wachtrij (lokaal,
     * een kleine site zonder werker) start er na een upload geen extern
     * programma in het webproces: dan leest alleen pure PHP de kop, na het
     * antwoord, en komt de poster van de browser, van
     * `dashed:media-previews --missing` of van de knop "Poster opnieuw
     * maken" ($explicit: één bestand, bewust gevraagd door een beheerder).
     */
    public function queue(int $itemId, bool $explicit = false): void
    {
        if ($itemId <= 0 || ! self::available()) {
            return;
        }

        rescue(function () use ($itemId, $explicit) {
            if (! self::queueIsSync()) {
                GenerateMediaPreview::dispatch($itemId);

                return;
            }

            GenerateMediaPreview::dispatch($itemId, ! $explicit)->afterResponse();
        });
    }

    /**
     * Het werk van de taak: het bestand bekijken, de kolommen vullen en de
     * voorvertoning maken. Gooit niet; de uitkomst is een status. Met
     * $programs false start er geen enkel programma en wordt er niets
     * opgehaald: alleen wat pure PHP uit de kop (en de staart) leest.
     *
     * @return array{status: string, width?: ?int, height?: ?int, duration?: ?float, pages?: ?int, poster?: bool}
     */
    public function generate(int $itemId, bool $programs = true): array
    {
        if (! self::available()) {
            return ['status' => 'unavailable'];
        }

        $item = MediaItem::withoutGlobalScope(MediaItem::SCOPE_WITH_FILE)->withTrashed()->find($itemId);
        $file = $item?->file();
        $mime = strtolower((string) ($file?->mime_type ?? ''));
        $kind = self::kindOf($mime);

        if (! $item || ! $file || $kind === null) {
            return ['status' => 'skipped'];
        }

        // Een voorvertoning van een vorig bestand (een poster die na het vervangen nog binnenkwam) gaat hier weg.
        $this->dropStalePreviews($item, $file);

        // Eén deadline voor alles: ophalen, bekijken en de poster. Elk programma krijgt hooguit wat er nog over is.
        $deadline = (float) config('dashed-files.preview.deadline', 40);

        return SafeProcess::withDeadline($deadline > 0 ? $deadline : 40.0, fn () => $this->look($item, $file, $mime, $kind, $programs));
    }

    /**
     * @return array{status: string, width?: ?int, height?: ?int, duration?: ?float, pages?: ?int, poster?: bool}
     */
    private function look(MediaItem $item, Media $file, string $mime, string $kind, bool $programs): array
    {
        $itemId = (int) $item->getKey();
        $max = (int) config('dashed-files.preview.max_bytes', 300 * 1024 * 1024);
        $tooLarge = $max > 0 && (int) $file->size > $max;

        // Eerst kiezen, dan pas ophalen: zonder generator komt het bestand niet naar de server.
        $capable = $tooLarge ? null : $this->generatorForFile($item, $file, $mime);
        $generator = $programs ? $capable : null;

        // Een ronde zonder programma's terwijl de server de poster wel kan
        // maken is niet het laatste woord: het item blijft dan "nog niet
        // bekeken", zodat `dashed:media-previews --missing` het oppakt.
        $final = $generator !== null || $capable === null;

        // Vooraf: een bestand waar de taak op sneuvelt komt zo niet bij elke ronde opnieuw aan de beurt.
        if ($final) {
            $this->touchChecked($itemId);
        }

        $local = null;
        $target = null;
        $meta = ['width' => null, 'height' => null, 'duration' => null, 'pages' => null];
        $poster = false;

        try {
            if ($generator === null) {
                // Geen programma (of het mag niet, of het bestand is te
                // groot): alleen een bereik lezen, nooit het hele bestand.
                $meta = $this->clean(array_merge($meta, $this->probeRange($file, $mime)), $kind);
                $this->write($item, $file, $meta, null, null, $final);

                return array_merge(['status' => $tooLarge ? 'too-large' : 'done', 'poster' => false], $meta);
            }

            $local = $this->fetch($file, $max);

            if ($local === null) {
                return ['status' => 'unreadable'];
            }

            // Het type van nu, volgens de inhoud: de inname heeft dit al
            // gedaan, maar een rij van voor de inname is nooit gesnoven.
            $sniffed = IngestPolicy::sniff($local, (string) $file->file_name);

            if (self::kindOf($sniffed) !== $kind) {
                return ['status' => 'mismatch'];
            }

            $generator = $this->generatorForFile($item, $file, $sniffed);

            if ($generator) {
                $meta = array_merge($meta, array_intersect_key($generator->probe($local), $meta));
            }

            // Als het programma niets gaf: wat pure PHP uit de kop haalt.
            if ($kind === self::VIDEO && in_array($sniffed, self::MP4_FAMILY, true) && ($meta['duration'] === null || $meta['width'] === null)) {
                foreach (Mp4Probe::probe($local) ?? [] as $key => $value) {
                    $meta[$key] ??= $value;
                }
            }

            if ($kind === self::PDF && $meta['pages'] === null) {
                $meta['pages'] = PdfProbe::pages($local);
            }

            $pixels = (int) ($meta['width'] ?? 0) * (int) ($meta['height'] ?? 0);
            $meta = $this->clean($meta, $kind);

            // Geen poster van een beeld boven de pixelgrens, en niet als de tijd op is.
            if ($generator && $pixels <= FfmpegPreview::maxPixels() && ! SafeProcess::expired()) {
                $target = IncomingMedia::temporaryPath();
                $poster = $generator->poster($local, $target);
            }

            $this->write($item, $file, $meta, $poster ? $target : null, $generator ? class_basename($generator) : null);

            return array_merge(['status' => 'done', 'poster' => $poster], $meta);
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => 'failed'];
        } finally {
            foreach (array_filter([$local, $target]) as $leftover) {
                if (is_file($leftover)) {
                    @unlink($leftover);
                }
            }
        }
    }

    /**
     * Een video heeft geen pagina's en een pdf geen duur of afmetingen, wat een generator ook teruggeeft.
     *
     * @param  array{width: mixed, height: mixed, duration: mixed, pages: mixed}  $meta
     * @return array{width: mixed, height: mixed, duration: mixed, pages: mixed}
     */
    private function clean(array $meta, string $kind): array
    {
        $meta[$kind === self::VIDEO ? 'pages' : 'duration'] = null;

        if ($kind === self::PDF) {
            $meta['width'] = $meta['height'] = null;
        }

        return $meta;
    }

    /**
     * Wat er zonder het bestand op te halen te lezen valt: de kop van een
     * mp4 (de stroom stopt zodra de kop gevonden is) of de kop en de staart
     * van een pdf. Voor elk ander type (avi, mpeg, ogg, mkv, webm) bestaat
     * er geen lezer in PHP en wordt er niets opgehaald.
     *
     * @return array<string, mixed>
     */
    private function probeRange(Media $file, string $mime): array
    {
        if (in_array($mime, self::MP4_FAMILY, true)) {
            return $this->probeRemoteHead($file) ?? [];
        }

        if ($mime !== 'application/pdf') {
            return [];
        }

        $stream = rescue(fn () => Storage::disk($file->disk)->readStream($file->getPathRelativeToRoot()), null, false);

        if (! is_resource($stream)) {
            return [];
        }

        try {
            return ['pages' => PdfProbe::pagesOfStream($stream, is_numeric($file->size) ? (int) $file->size : null)];
        } finally {
            @fclose($stream);
        }
    }

    /** Gooit een automatische voorvertoning weg die niet bij het huidige bestand hoort. */
    private function dropStalePreviews(MediaItem $item, Media $file): void
    {
        $dropped = false;

        foreach ($this->previewRows($item->getKey())->get() as $row) {
            if ((int) $row->getCustomProperty('preview_of') !== (int) $file->getKey()) {
                rescue(fn () => $row->delete());
                $dropped = true;
            }
        }

        if ($dropped) {
            $item->unsetRelation('media');
        }
    }

    /**
     * De automatische voorvertoning, maar alleen als die van het huidige
     * bestand gemaakt is (`preview_of`). Een poster van een vervangen
     * bestand die nog binnenkwam wordt zo nooit getoond; de volgende taak
     * ruimt hem op.
     */
    public function currentPreview(MediaItem $item): ?Media
    {
        $file = $item->file();
        $preview = $item->preview();
        $of = $preview?->getCustomProperty('preview_of');

        return $file && $preview && is_numeric($of) && (int) $of === (int) $file->getKey() ? $preview : null;
    }

    /**
     * Bewaart een jpeg als de voorvertoning van het item en gooit de vorige
     * weg. Het bestand wordt hier nog één keer bekeken: het moet echt een
     * jpeg zijn. Geeft null als het geweigerd is.
     */
    public function storePoster(MediaItem $item, string $jpegPath, string $origin, ?int $forMediaId = null): ?Media
    {
        if (! FfmpegPreview::isJpeg($jpegPath) || IngestPolicy::sniff($jpegPath, self::POSTER_FILE_NAME) !== 'image/jpeg') {
            return null;
        }

        $previous = $this->previewRows($item->getKey())->get();

        $media = $item->addMedia($jpegPath)
            ->usingFileName(self::POSTER_FILE_NAME)
            ->usingName('poster')
            ->withCustomProperties(['preview_origin' => mb_substr($origin, 0, 40), 'preview_of' => $forMediaId])
            ->addCustomHeaders(['ContentType' => 'image/jpeg'])
            ->toMediaCollection(MediaItem::COLLECTION_PREVIEW, \Dashed\DashedFiles\Classes\Access\MediaVisibility::diskFor($item));

        // Pas nu de vorige weg: mislukt het bewaren, dan blijft die staan.
        foreach ($previous as $old) {
            rescue(fn () => $old->delete());
        }

        $item->unsetRelation('media');

        return $media;
    }

    /**
     * Een poster die de browser bij het uploaden zelf maakte (de server
     * heeft geen ffmpeg). Krijgt dezelfde controles als elke afbeelding
     * (wachter, snuiven, megapixels) en moet een kleine jpeg zijn.
     */
    public function acceptBrowserPoster(MediaItem $item, string $localPath): bool
    {
        if (! self::available() || $this->serverMakesVideoPosters()) {
            return false;
        }

        $file = $item->file();

        if (! $file || self::kindOf($file->mime_type) !== self::VIDEO) {
            return false;
        }

        try {
            if (! is_file($localPath) || filesize($localPath) > (int) config('dashed-files.preview.max_poster_bytes', 2 * 1024 * 1024)) {
                return false;
            }

            // De inname-controles, zonder iets te bewaren. Alleen een jpeg komt erdoor.
            $prepared = app(MediaLibrary::class)->prepare(new IncomingMedia(
                localPath: $localPath,
                fileName: self::POSTER_FILE_NAME,
                source: 'upload',
                allowedMimes: ['image/jpeg'],
                deleteLocalAfter: false,
            ));

            $pixels = ($prepared->dimensions['width'] ?? 0) * ($prepared->dimensions['height'] ?? 0);

            if ($prepared->mime !== 'image/jpeg' || $pixels <= 0 || $pixels > (float) config('dashed-files.preview.max_poster_megapixels', 4) * 1_000_000) {
                return false;
            }

            $stored = $this->storePoster($item, $prepared->path, 'browser', (int) $file->getKey()) !== null;

            if ($stored) {
                app(MediaLibrary::class)->invalidate($item, 'preview');
            }

            return $stored;
        } catch (MediaRejectedException) {
            return false;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /** Gooit de automatische voorvertoning van een item weg (bij vervangen). */
    public function dropPreview(int $itemId): int
    {
        $dropped = 0;

        foreach ($this->previewRows($itemId)->get() as $row) {
            rescue(function () use ($row, &$dropped) {
                $row->delete();
                $dropped++;
            });
        }

        return $dropped;
    }

    /**
     * De voorvertoning zoals het leespad hem geeft.
     *
     * @return array{url: string, custom: bool, item_id: ?int}|null
     */
    public function posterFor(MediaItem $item): ?array
    {
        $posterId = BrowseSchema::hasPreview() ? $item->getAttribute('poster_item_id') : null;

        if (is_numeric($posterId) && (int) $posterId > 0) {
            // Ook uit de prullenbak: de site blijft een weggegooid bestand tonen tot het echt weg is.
            $poster = $item->relationLoaded('posterItem')
                ? $item->getRelation('posterItem')
                : rescue(fn () => MediaItem::withTrashed()->find((int) $posterId), null, false);
            $file = $poster?->file();

            // Een privé-afbeelding heeft geen adres: dan geldt de automatische voorvertoning.
            if ($poster && \Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($poster)) {
                $file = null;
            }

            if ($file && MediaItemPayload::typeOf($file->mime_type) === 'image' && ($url = $this->imageUrl($poster, $file)) !== null) {
                return ['url' => $url, 'custom' => true, 'item_id' => (int) $poster->getKey()];
            }
        }

        $preview = $this->currentPreview($item);

        // De voorvertoning van een privé-item staat op de privé-schijf: het beheer opent hem via de ondertekende route.
        if ($preview && \Dashed\DashedFiles\Classes\Access\MediaVisibility::isPrivate($item)) {
            $url = MediaLibrary::privateUrl($item, null, 'preview');

            return is_string($url) && $url !== '' ? ['url' => $url, 'custom' => false, 'item_id' => null] : null;
        }

        $url = $preview ? rescue(fn () => $preview->getUrl(), null, false) : null;

        return is_string($url) && $url !== '' ? ['url' => $url, 'custom' => false, 'item_id' => null] : null;
    }

    /**
     * Wat getSingleMedia() er voor een video of pdf bij krijgt. Alleen
     * gevulde sleutels: voor elk ander item blijft het resultaat precies wat het was.
     *
     * @return array<string, mixed>
     */
    public function deliveryExtras(MediaItem $item, ?string $mime): array
    {
        $kind = self::kindOf($mime);

        if ($kind === null) {
            return [];
        }

        $extras = [];

        if ($poster = $this->posterFor($item)) {
            $extras['poster'] = $poster['url'];
        }

        if ($kind === self::VIDEO && ($duration = PreviewNumbers::duration($item->getAttribute('duration'))) !== null) {
            $extras['duration'] = $duration;
        }

        if ($kind === self::PDF && ($pages = PreviewNumbers::pages($item->getAttribute('pages'))) !== null) {
            $extras['pages'] = $pages;
        }

        return $extras;
    }

    /** Leegt de cache van elk item dat deze afbeelding als poster gebruikt. */
    public function invalidateUsersOfPoster(int $posterItemId): void
    {
        if ($posterItemId <= 0 || ! BrowseSchema::hasPreview()) {
            return;
        }

        rescue(function () use ($posterItemId) {
            $ids = MediaItem::withoutGlobalScopes()->toBase()->where('poster_item_id', $posterItemId)->limit(500)->pluck('id');

            foreach ($ids as $id) {
                app(MediaLibrary::class)->invalidate((int) $id, 'poster-changed');
            }
        }, report: false);
    }

    /**
     * Video's en pdf's die nog bekeken moeten worden: nooit bekeken. Wat al
     * bekeken is en geen voorvertoning kreeg (te groot, corrupt, geen
     * generator) komt niet vanzelf terug. Alleen met $retry: dan ook wat
     * langer dan een dag geleden bekeken is zonder voorvertoning terwijl de
     * server die nu wel kan maken.
     */
    public function missingQuery(bool $retry = false): Builder
    {
        $table = (new MediaItem())->getTable();
        $kinds = $retry ? array_filter([
            self::VIDEO => $this->generatorFor('video/mp4') !== null,
            self::PDF => $this->generatorFor('application/pdf') !== null,
        ]) : [];

        return $this->kindQuery()->where(function (Builder $query) use ($table, $kinds) {
            $query->whereNull($table . '.preview_checked_at');

            if ($kinds !== []) {
                $query->orWhere(fn (Builder $again) => $again
                    ->where($table . '.preview_checked_at', '<', now()->subDay())
                    ->whereDoesntHave('media', fn (Builder $media) => $media->where('collection_name', MediaItem::COLLECTION_PREVIEW))
                    ->whereHas('media', fn (Builder $media) => $media->where('collection_name', MediaItem::COLLECTION)->where(function (Builder $mime) use ($kinds) {
                        isset($kinds[self::VIDEO]) ? $mime->whereIn('mime_type', FfmpegPreview::MIMES) : $mime->whereRaw('1 = 0');

                        if (isset($kinds[self::PDF])) {
                            $mime->orWhere('mime_type', 'application/pdf');
                        }
                    })));
            }
        });
    }

    /** Alle video's en pdf's van de bibliotheek (niet in de prullenbak). */
    public function kindQuery(?string $kind = null): Builder
    {
        return MediaItem::query()->whereHas('media', fn (Builder $media) => $media
            ->where('collection_name', MediaItem::COLLECTION)
            ->where(function (Builder $mime) use ($kind) {
                if ($kind !== self::PDF) {
                    $mime->where('mime_type', 'like', 'video/%');
                }

                if ($kind !== self::VIDEO) {
                    $mime->orWhere('mime_type', 'application/pdf');
                }
            }));
    }

    private function previewRows(int|string $itemId)
    {
        return Media::query()
            ->where('model_id', $itemId)
            ->whereIn('model_type', [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS])
            ->where('collection_name', MediaItem::COLLECTION_PREVIEW);
    }

    private function touchChecked(int $itemId): void
    {
        MediaItem::withoutGlobalScopes()->toBase()->where('id', $itemId)->update(['preview_checked_at' => now()]);
    }

    /**
     * Haalt het bestand naar storage/app/media-ingest, onder een naam die
     * wij kiezen. Null als het er niet is of groter blijkt dan de grens.
     */
    private function fetch(Media $file, int $max): ?string
    {
        $stream = rescue(fn () => Storage::disk($file->disk)->readStream($file->getPathRelativeToRoot()), null, false);

        if (! is_resource($stream)) {
            return null;
        }

        $local = IncomingMedia::temporaryPath();
        $target = @fopen($local, 'wb');
        // Eén byte meer dan de grens: zo is te zien dat het bestand groter is dan de rij zegt.
        $limit = $max > 0 ? $max + 1 : PHP_INT_MAX;
        $copied = $target ? 0 : false;

        // In stukken, zodat een trage schijf de deadline van de ronde niet voorbij loopt.
        while ($target && $copied < $limit && ! feof($stream)) {
            if (SafeProcess::expired()) {
                $copied = false;

                break;
            }

            $chunk = stream_copy_to_stream($stream, $target, min(8 * 1024 * 1024, $limit - $copied));

            if ($chunk === false || $chunk === 0) {
                break;
            }

            $copied += $chunk;
        }

        fclose($stream);

        if ($target) {
            fclose($target);
        }

        if ($copied === false || $copied === 0 || ($max > 0 && $copied > $max)) {
            @unlink($local);

            return null;
        }

        return $local;
    }

    /**
     * @return array{width: ?int, height: ?int, duration: ?float}|null
     */
    private function probeRemoteHead(Media $file): ?array
    {
        $stream = rescue(fn () => Storage::disk($file->disk)->readStream($file->getPathRelativeToRoot()), null, false);

        if (! is_resource($stream)) {
            return null;
        }

        try {
            return Mp4Probe::probeStream($stream, is_numeric($file->size) ? (int) $file->size : null);
        } finally {
            @fclose($stream);
        }
    }

    /**
     * Schrijft wat gevonden is, maar alleen als het item nog hetzelfde
     * bestand heeft als waar de taak mee begon: na een vervanging hoort de
     * uitkomst bij het oude bestand en doet de volgende taak het werk.
     *
     * @param  array{width: ?int, height: ?int, duration: ?float, pages: ?int}  $meta
     */
    private function write(MediaItem $item, Media $file, array $meta, ?string $posterPath, ?string $origin, bool $markChecked = true): void
    {
        $current = Media::query()->whereKey($file->getKey())->first();

        if (! $current || $current->collection_name !== MediaItem::COLLECTION) {
            return;
        }

        $width = PreviewNumbers::dimension($meta['width']);
        $height = PreviewNumbers::dimension($meta['height']);

        // De afmetingen horen op de mediarij: de spiegelkolommen en
        // getSingleMedia() lezen ze daar.
        if ($width !== null && $height !== null
            && ((int) $current->getCustomProperty('original_width') !== $width || (int) $current->getCustomProperty('original_height') !== $height)) {
            $current->setCustomProperty('original_width', $width);
            $current->setCustomProperty('original_height', $height);
            $current->saveQuietly();
        }

        MediaItem::withoutGlobalScopes()->toBase()->where('id', $item->getKey())->update(array_merge(
            [
                'duration' => PreviewNumbers::duration($meta['duration']),
                'pages' => PreviewNumbers::pages($meta['pages']),
            ],
            $markChecked ? ['preview_checked_at' => now()] : [],
            $width !== null && $height !== null && BrowseSchema::hasMirror() ? ['width' => $width, 'height' => $height] : [],
        ));

        if ($posterPath !== null) {
            $this->storePoster($item, $posterPath, (string) $origin, (int) $current->getKey());
        }

        MediaPickerFileCache::flush();
        app(MediaLibrary::class)->invalidate($item, 'preview');
    }

    private function imageUrl(MediaItem $poster, Media $file): ?string
    {
        $original = rescue(fn () => $file->getUrl(), null, false);

        if ($file->mime_type !== 'image/gif' && $file->hasGeneratedConversion('medium')) {
            // De registratie van formaten zoekt de kolom `conversions` per item op; het item is hier al geladen.
            MediaPickerFileCache::rememberConversions($poster->getKey(), fn () => $poster->conversions);
            $medium = rescue(fn () => $file->getUrl('medium'), null, false);

            if (is_string($medium) && $medium !== '') {
                return $medium;
            }
        }

        return is_string($original) && $original !== '' ? $original : null;
    }
}
