<?php

namespace Dashed\DashedFiles\Classes\Access;

use Throwable;
use RuntimeException;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedFiles\Models\MediaItem;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Illuminate\Contracts\Filesystem\Filesystem;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\MediaPickerFileCache;
use Dashed\DashedFiles\Jobs\FlushMediaDependentCaches;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Dashed\DashedFiles\Classes\Conversions\ConversionPump;

/**
 * Verhuist de bestanden van één item tussen de publieke en de privé-schijf:
 * het bestand zelf, elk formaat, de voorvertoning en wat er van een
 * vervanging of bewerking bewaard is (elke mediarij van het item, met alles
 * in zijn map).
 *
 * De volgorde per mediarij is vast: eerst kopiëren en nameten, dan de rij
 * omzetten, dan pas de bron weg. Valt het proces ergens weg, dan staat het
 * bestand dus altijd nog op minstens één van de twee schijven, en een
 * volgende poging maakt af wat er lag: een rij die al om is krijgt alleen
 * nog zijn restanten op de andere schijf opgeruimd. Het item blijft al die
 * tijd dicht; pas als alles terug op de publieke schijf staat gaat het open.
 *
 * Hervatbaar per bestand: wat al met dezelfde grootte op het doel staat
 * wordt overgeslagen, en tussen twee bestanden wordt het tijdsbudget
 * getoetst. Een item dat niet in één slag past gaat dus over meerdere
 * slagen; de rij gaat pas om als alles op het doel staat, en de bron blijft
 * tot dan compleet.
 *
 * SLOTEN, altijd in deze volgorde (nooit andersom, dan kan er geen kring
 * ontstaan):
 *   1. het itemslot `media-replace-{item}` (ook van vervangen en bewerken);
 *   2. per mediarij het slot van de pomp, ConversionPump::mediaLockKey().
 * De pomp en RegenerateMediaLibraryConversions nemen alleen slot 2 en nooit
 * daarna slot 1. Is slot 2 bezet, dan wacht de verhuizer kort en stopt de
 * slag zonder dat het als mislukt telt. Verloopt een slot toch tijdens lang
 * werk, dan vangt GuardedMediaFilesystem het af: een formaat wordt niet
 * weggeschreven als de schijf van de rij intussen is gewisseld.
 */
final class MediaRelocator
{
    public const DONE = 'done';

    public const BUSY = 'busy';

    public const FAILED = 'failed';

    public const GAVE_UP = 'gave_up';

    // Een bestand is groter dan de wachtrijtaak mag kopiëren: geen mislukking, het item wacht op --now.
    public const TOO_LARGE = 'too_large';

    public const DEFAULT_MAX_ATTEMPTS = 5;

    // Voor `dashed:media-private relocate --now`, zonder tijdslimiet: een slot dat tijdens een lange kopie verloopt is erger dan een slot dat na een gesneuveld proces even blijft staan.
    public const DEFAULT_LOCK_SECONDS = 960;

    // Voor de wachtrijtaak: zijn time-out (85 s) plus 35 s marge. Een taak die
    // sneuvelt houdt een item zo hooguit twee minuten vast in plaats van een
    // kwartier, en een levende taak raakt zijn slot nooit kwijt: hij begint na
    // zijn budget (40 s) geen nieuw bestand meer en een bestand groter dan
    // `queue_max_mb` begint hij helemaal niet.
    public const QUEUE_LOCK_SECONDS = 120;

    public const DEFAULT_QUEUE_MAX_MB = 100;

    /**
     * Een test hangt hier een storing aan: aangeroepen met de stap
     * (`copied`, `switched`, `cleaned`) en de mediarij.
     *
     * @var (callable(string, Media): void)|null
     */
    public static $afterStep = null;

    /**
     * Voor tests: aangeroepen na elk gekopieerd bestand, met het pad.
     *
     * @var (callable(string): void)|null
     */
    public static $afterFile = null;

    /**
     * Voor tests: de klok.
     *
     * @var (callable(): float)|null
     */
    public static $clock = null;

    /** Hoe lang de verhuizer op het slot van een mediarij wacht, in seconden. */
    public static int $mediaLockWait = 5;

    private ?float $deadline = null;

    private float $lockedAt = 0.0;

    private int $copied = 0;

    private ?Lock $itemLock = null;

    private int $lockTtl = self::DEFAULT_LOCK_SECONDS;

    private ?int $maxBytes = null;

    public static function maxAttempts(): int
    {
        $max = (int) config('dashed-files.private.max_attempts', self::DEFAULT_MAX_ATTEMPTS);

        return $max > 0 ? $max : self::DEFAULT_MAX_ATTEMPTS;
    }

    public static function lockSeconds(): int
    {
        return max(120, (int) config('dashed-files.private.lock_seconds', self::DEFAULT_LOCK_SECONDS));
    }

    /**
     * Het grootste bestand dat de wachtrijtaak kopieert, in bytes. Eén
     * bestand is niet te onderbreken; een groter bestand haalt de time-out
     * van 85 seconden niet zeker. 0 = geen grens.
     */
    public static function queueMaxBytes(): int
    {
        $mb = config('dashed-files.private.queue_max_mb', self::DEFAULT_QUEUE_MAX_MB);

        return (is_numeric($mb) ? max(0, (int) $mb) : self::DEFAULT_QUEUE_MAX_MB) * 1024 * 1024;
    }

    /**
     * Eén poging, zonder dat er iets naar buiten gegooid wordt. Een
     * mislukking telt op het item; na het ingestelde aantal geeft de
     * minuuttaak het op: het item blijft dicht en wordt één keer gemeld.
     *
     * @param  float|null  $deadline  het moment (microtime) waarna geen nieuw bestand meer begonnen wordt; met een deadline geldt het korte slot van de wachtrijtaak
     * @param  int|null  $maxBytes  een bestand daarboven wordt niet gekopieerd (de wachtrijtaak); null is geen grens
     * @return string DONE, BUSY (bezet of het budget is op: geen mislukking), TOO_LARGE, FAILED of GAVE_UP
     */
    public function attempt(int $itemId, ?float $deadline = null, ?int $maxBytes = null): string
    {
        try {
            return $this->relocate($itemId, $deadline, $maxBytes) ? self::DONE : self::BUSY;
        } catch (RelocationTooLarge) {
            return self::TOO_LARGE;
        } catch (Throwable $exception) {
            return $this->registerFailure($itemId, $exception);
        }
    }

    /**
     * @param  float|null  $deadline  het moment (microtime) waarna geen nieuw bestand meer begonnen wordt
     * @param  int|null  $maxBytes  een bestand daarboven wordt niet gekopieerd; null is geen grens
     * @return bool of het item nu op zijn plek staat (false: bezet, of het tijdsbudget is op)
     *
     * @throws RelocationTooLarge als een bestand groter is dan $maxBytes (er is dan niets veranderd aan de rijen)
     * @throws Throwable als een bestand niet te kopiëren, na te meten of op te ruimen is
     */
    public function relocate(int $itemId, ?float $deadline = null, ?int $maxBytes = null): bool
    {
        if (! BrowseSchema::hasAccess()) {
            return true;
        }

        // Met een deadline is dit de wachtrijtaak: het korte slot, zodat een
        // gesneuvelde taak het item niet een kwartier vasthoudt.
        $this->lockTtl = $deadline === null ? self::lockSeconds() : self::QUEUE_LOCK_SECONDS;
        $this->maxBytes = $maxBytes !== null && $maxBytes > 0 ? $maxBytes : null;

        // Slot 1. Hetzelfde slot als vervangen en bewerken: die zetten mediarijen om.
        $lock = Cache::lock("media-replace-{$itemId}", $this->lockTtl);

        if (! $lock->get()) {
            return false;
        }

        $this->itemLock = $lock;
        $this->lockedAt = $this->now();
        $this->deadline = $deadline;
        $this->copied = 0;

        try {
            $row = MediaItem::withoutGlobalScopes()->toBase()->where('id', $itemId)->first(['id', 'is_private', 'relocate_to']);

            if (! $row || $row->relocate_to === null) {
                return true;
            }

            $toPrivate = $row->relocate_to === MediaVisibility::TO_PRIVATE;
            $target = MediaStorage::diskFor($toPrivate);
            $other = MediaStorage::diskFor(! $toPrivate);

            if ($target === $other) {
                throw new RuntimeException('The private media disk is the same as the public one.');
            }

            $rows = Media::query()
                ->whereIn('model_type', [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS])
                ->where('model_id', $itemId)
                ->orderBy('id')
                ->get();

            // Vooraf, op de grootte die de rij kent: een te groot bestand
            // begint de wachtrijtaak niet, ook niet half.
            if ($this->maxBytes !== null && $rows->contains(fn (Media $media) => (int) $media->size > $this->maxBytes)) {
                throw new RelocationTooLarge("Media item #{$itemId} has a file larger than the queue job copies.");
            }

            foreach ($rows as $media) {
                // Slot 2: geen pomp of losse generatie op deze rij terwijl hij verhuist.
                $mediaLock = Cache::lock(ConversionPump::mediaLockKey($media->getKey()), $this->lockTtl);

                if (! rescue(fn () => $mediaLock->block(self::$mediaLockWait), false, false)) {
                    return false;
                }

                try {
                    $this->relocateMedia($media, $target, $other, $toPrivate);
                } finally {
                    rescue(fn () => $mediaLock->release(), report: false);
                }
            }

            // Na "cleaned": er hoort van dit item niets meer op de andere
            // schijf te staan. Wat een andere schrijver er intussen toch
            // neerzette (een formaat van de pomp) gaat hier weg.
            foreach ($rows as $media) {
                $this->sweep($media, $target, $other);
            }

            // Alles staat op zijn plek. Voorwaardelijk: is de opdracht
            // intussen omgedraaid (de map ging weer dicht), dan blijft die staan.
            $done = MediaItem::withoutGlobalScopes()->toBase()
                ->where('id', $itemId)
                ->where('relocate_to', $row->relocate_to)
                ->update(array_merge(
                    ['relocate_to' => null, 'conversion_urls' => null],
                    $toPrivate ? ['is_private' => true] : ['is_private' => false],
                    BrowseSchema::hasRelocateProgress() ? ['relocate_since' => null, 'relocate_attempts' => null, 'relocate_gave_up_at' => null] : [],
                ));

            rescue(fn () => app(MediaLibrary::class)->invalidate($itemId, 'visibility'));
            MediaPickerFileCache::flush();
            rescue(fn () => FlushMediaDependentCaches::queueFor($itemId));

            return $done > 0;
        } catch (RelocationPaused) {
            // Het budget is op of een slot dreigt te verlopen: de volgende slag gaat verder waar deze bleef.
            return false;
        } finally {
            $this->itemLock = null;
            rescue(fn () => $lock->release(), report: false);
        }
    }

    /**
     * Telt een mislukte poging en geeft het na het ingestelde aantal op.
     */
    private function registerFailure(int $itemId, Throwable $exception): string
    {
        $message = mb_substr($exception->getMessage() ?: get_class($exception), 0, 300);

        if (! BrowseSchema::hasRelocateProgress()) {
            report($exception);

            return self::FAILED;
        }

        return (string) rescue(function () use ($itemId, $message, $exception) {
            $query = fn () => MediaItem::withoutGlobalScopes()->toBase()->where('id', $itemId)->whereNotNull('relocate_to');
            $attempts = (int) $query()->value('relocate_attempts') + 1;
            $query()->update(['relocate_attempts' => $attempts]);

            if ($attempts < self::maxAttempts()) {
                Log::warning("Media item #{$itemId}: moving between the media disks failed (attempt {$attempts}): {$message}");

                return self::FAILED;
            }

            // Eén keer: alleen de schrijver die het stempel zet meldt het.
            if ($query()->whereNull('relocate_gave_up_at')->update(['relocate_gave_up_at' => now()]) > 0) {
                report(new RuntimeException("Media item #{$itemId} could not be moved between the media disks after {$attempts} attempts and stays closed. Retry with dashed:media-private relocate --force. Last error: {$message}", 0, $exception));
            }

            return self::GAVE_UP;
        }, self::FAILED, false);
    }

    private function relocateMedia(Media $media, string $target, string $other, bool $toPrivate): void
    {
        $directory = trim((string) $media->uuid, '/');

        // Zonder eigen map is niet te zeggen welke bestanden van deze rij zijn.
        if ($directory === '' || ! preg_match('/^[A-Za-z0-9-]{8,}$/', $directory)) {
            throw new RuntimeException("Media #{$media->getKey()} has no usable directory.");
        }

        $targetDisk = Storage::disk($target);
        $main = $directory . '/' . $media->file_name;
        $onTarget = $media->disk === $target && ($media->conversions_disk ?: $media->disk) === $target;
        $missing = false;

        if (! $onTarget) {
            // De bron is waar de rij nu naar wijst; formaten kunnen op een eigen schijf staan.
            $from = array_values(array_filter(array_unique(array_filter([$media->disk, $media->conversions_disk])), fn (string $source) => $source !== $target));

            foreach ($from as $source) {
                $this->copyDirectory(Storage::disk($source), $targetDisk, $directory, $toPrivate, $main, (string) $media->mime_type);
            }

            $this->step('copied', $media);

            if (! $targetDisk->exists($main)) {
                foreach ($from as $source) {
                    if (Storage::disk($source)->exists($main)) {
                        throw new RuntimeException("Media #{$media->getKey()}: the file did not arrive on disk [{$target}].");
                    }
                }

                // Het bestand staat op geen van beide schijven (oude
                // bibliotheek, met de hand opgeruimd). Er valt niets te
                // verhuizen: de rij gaat om en het item rondt af.
                $missing = true;
                Log::warning("Media #{$media->getKey()} (item #{$media->model_id}): the file [{$main}] is on neither media disk; the row was switched to disk [{$target}] without a file.");
            }

            $this->assertStillLocked();

            Media::query()->whereKey($media->getKey())->toBase()->update(['disk' => $target, 'conversions_disk' => $target]);
            $sources = array_unique(array_filter([$media->disk, $media->conversions_disk, $other]));
            $media->disk = $target;
            $media->conversions_disk = $target;
            $media->syncOriginal();

            $this->step('switched', $media);
        } else {
            $sources = [$other];
        }

        // De bron weg, maar alleen als het bestand echt op zijn bestemming staat.
        foreach ($sources as $source) {
            if ($source === $target) {
                continue;
            }

            $sourceDisk = Storage::disk($source);

            if ($sourceDisk->allFiles($directory) === []) {
                continue;
            }

            // Staat het bestand zelf nog op de bron en niet op het doel, dan blijft de bron staan.
            if (! $missing && ! $targetDisk->exists($main) && $sourceDisk->exists($main)) {
                throw new RuntimeException("Media #{$media->getKey()}: refusing to remove the source, the file is missing on disk [{$target}].");
            }

            $sourceDisk->deleteDirectory($directory);

            if ($sourceDisk->allFiles($directory) !== []) {
                throw new RuntimeException("Media #{$media->getKey()}: the source on disk [{$source}] could not be removed.");
            }
        }

        $this->step('cleaned', $media);
    }

    /**
     * De controle na "cleaned": de map van deze rij op de andere schijf
     * hoort leeg te zijn. Resten gaan weg, zolang het bestand zelf er niet
     * alleen daar staat.
     */
    private function sweep(Media $media, string $target, string $other): void
    {
        $directory = trim((string) $media->uuid, '/');
        $otherDisk = Storage::disk($other);

        if ($directory === '' || $otherDisk->allFiles($directory) === []) {
            return;
        }

        $main = $directory . '/' . $media->file_name;

        if ($otherDisk->exists($main) && ! Storage::disk($target)->exists($main)) {
            throw new RuntimeException("Media #{$media->getKey()}: the file is back on disk [{$other}] and missing on disk [{$target}].");
        }

        $otherDisk->deleteDirectory($directory);

        if ($otherDisk->allFiles($directory) !== []) {
            throw new RuntimeException("Media #{$media->getKey()}: leftovers on disk [{$other}] could not be removed.");
        }
    }

    /**
     * Kopieert alles onder de map van een mediarij en meet elk bestand na.
     * Wat al met dezelfde grootte op het doel staat wordt overgeslagen.
     */
    private function copyDirectory(Filesystem $source, Filesystem $target, string $directory, bool $toPrivate, string $main, string $mime): void
    {
        foreach ($source->allFiles($directory) as $path) {
            $size = $source->size($path);

            if ($target->exists($path) && $target->size($path) === $size) {
                continue;
            }

            // Een bestand dat de rij niet kent (een bewaarde bron) of waarvan de rij een verkeerde grootte heeft.
            if ($this->maxBytes !== null && $size > $this->maxBytes) {
                throw new RelocationTooLarge("[{$path}] is larger than the queue job copies.");
            }

            // Tussen twee bestanden: is het budget op, of nadert het slot zijn
            // einde, dan stopt deze slag. Minstens één bestand per slag, anders
            // komt een item met een krap budget nooit vooruit.
            if ($this->copied > 0 && $this->outOfTime()) {
                throw new RelocationPaused();
            }

            $stream = $source->readStream($path);

            if (! is_resource($stream)) {
                throw new RuntimeException("Could not read [{$path}].");
            }

            try {
                $options = ['visibility' => $toPrivate ? 'private' : 'public'];

                // Het bestand zelf houdt het type waaronder het is ingenomen (een heic, een opgeschoonde svg).
                if ($path === $main && $mime !== '') {
                    $options['ContentType'] = $mime;
                }

                // Naar de publieke schijf: dezelfde header als een nieuw bestand krijgt. Naar privé niets.
                if (! $toPrivate && ($cache = \Dashed\DashedFiles\Classes\MediaCacheControl::forPath($path, $path === $main ? $mime : '')) !== null) {
                    $options['CacheControl'] = $cache;
                }

                if ($target->writeStream($path, $stream, $options) === false) {
                    throw new RuntimeException("Could not write [{$path}].");
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $target->exists($path) || $target->size($path) !== $size) {
                throw new RuntimeException("The copy of [{$path}] does not match its source.");
            }

            $this->copied++;

            if (is_callable(self::$afterFile)) {
                (self::$afterFile)($path);
            }
        }
    }

    private function outOfTime(): bool
    {
        $now = $this->now();

        return ($this->deadline !== null && $now >= $this->deadline)
            // Een minuut marge voor het omzetten en opruimen.
            || $now - $this->lockedAt > $this->lockTtl - 60;
    }

    /**
     * Vlak voor de rij omgaat: is het itemslot tijdens een lange kopie
     * verlopen en door een ander genomen, dan gaat deze slag niet verder.
     */
    private function assertStillLocked(): void
    {
        if ($this->now() - $this->lockedAt > $this->lockTtl
            || ($this->itemLock && method_exists($this->itemLock, 'isOwnedByCurrentProcess') && ! $this->itemLock->isOwnedByCurrentProcess())) {
            throw new RelocationPaused();
        }
    }

    private function now(): float
    {
        return self::$clock ? (float) (self::$clock)() : microtime(true);
    }

    private function step(string $step, Media $media): void
    {
        if (is_callable(self::$afterStep)) {
            (self::$afterStep)($step, $media);
        }
    }
}
