<?php

namespace Dashed\DashedFiles\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use Dashed\DashedFiles\Models\MediaItem;

class MediaDoctorCommand extends Command
{
    // Boven dit aantal rijen wordt alleen het nieuwste deel van een
    // wachtrijtabel doorzocht: een LIKE op de payload gebruikt geen index.
    public const QUEUE_SCAN_LIMIT = 5000;

    // Boven dit aantal ontbrekende formaten krijgt de meting een
    // waarschuwing: elk paar is na uitrol een herkansing in de wachtrij.
    public const MISSING_CONVERSIONS_WARNING = 500;

    private const PACKAGE_NEEDLE = 'RalphJSmit';

    private const ITEMS = 'filament_media_library';

    private const FOLDERS = 'filament_media_library_folders';

    public $signature = 'dashed:media:doctor {--json : Output the measurements as JSON}';

    public $description = 'Read-only health check of the media library tables';

    public function handle(): int
    {
        $checks = array_map(fn (string $method): array => $this->{$method}(), $this->checks());
        $healthy = collect($checks)->every(fn (array $check): bool => $check['ok']);

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $healthy, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $healthy ? self::SUCCESS : self::FAILURE;
        }

        foreach ($checks as $check) {
            $value = is_array($check['value']) ? json_encode($check['value'], JSON_UNESCAPED_SLASHES) : ($check['value'] ?? '-');
            $status = ! $check['ok'] ? 'PROBLEM' : ($check['value'] === null ? 'INFO' : 'OK');
            $line = sprintf('[%s] %s: %s', $status, $check['label'], $value);

            if ($check['detail'] !== null) {
                $line .= ' (' . $check['detail'] . ')';
            }

            $check['ok'] ? $this->line($line) : $this->error($line);
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * De metingen, in de volgorde van de uitvoer. Een latere fase zet er een
     * methodenaam bij.
     *
     * @return array<int, string>
     */
    protected function checks(): array
    {
        return [
            'checkEngine',
            'checkItems',
            'checkFolders',
            'checkItemsWithoutMedia',
            'checkMediaByModelType',
            'checkMediaUnderLegacyClass',
            'checkMissingConversions',
            'checkOrphanedMedia',
            'checkItemsWithMissingFolder',
            'checkQueuedJobs',
            'checkFailedJobs',
            'checkImageDriver',
            'checkCapabilities',
            'checkConversionRuns',
            'checkGivenUpConversions',
            'checkUsageIndex',
            'checkUnbuildableBlocks',
            'checkTextMirror',
            'checkMirrorLocale',
            'checkAltTextOnUpload',
            'checkMediaProcessing',
            'checkPreviews',
            'checkChecksumsTooLargeForQueue',
            'checkUnsanitizedSvg',
            'checkPrivateDisk',
            'checkPrivateItems',
            'checkPrivateRelocating',
            'checkPrivateOpenItems',
            'checkPrivateDiskAnonymous',
            'checkSources',
            'checkLegacyAliases',
            'checkCodeTrash',
        ];
    }

    /**
     * Welke motor draait en waarom. Altijd in orde; `legacy` krijgt een
     * toelichting, want dat is sinds release A een bewuste uitzondering.
     */
    protected function checkEngine(): array
    {
        $engine = \Dashed\DashedFiles\Classes\MediaLibrary::engine();
        $source = \Dashed\DashedFiles\Classes\MediaLibrary::engineSource();

        return $this->result('engine', 'Media engine', $engine . ' (' . $source . ')', true, match (true) {
            $engine === \Dashed\DashedFiles\Classes\MediaLibrary::ENGINE_LEGACY && $source === 'env' => 'forced to legacy by DASHED_MEDIA_ENGINE; remove it to run the own library',
            $engine === \Dashed\DashedFiles\Classes\MediaLibrary::ENGINE_LEGACY => 'legacy was chosen explicitly; dashed:media-engine dashed switches to the own library',
            default => null,
        });
    }

    // Waar een project eigen code heeft die de oude klassenamen kan noemen.
    public const LEGACY_SCAN_DIRECTORIES = ['app', 'database', 'resources/views'];

    /**
     * Of de oude klassenamen van het pakket naar onze modellen wijzen
     * (LegacyClassAliases), of het pakket nog geïnstalleerd is, en hoeveel
     * bestanden van het project de oude namen nog noemen. Dat tellen leest
     * de schijf en gebeurt daarom alleen hier, op commando, nooit per
     * verzoek. Altijd in orde: met de aliassen aan werkt die code gewoon.
     */
    protected function checkLegacyAliases(): array
    {
        $enabled = \Dashed\DashedFiles\Classes\Legacy\LegacyClassAliases::$enabled;
        $installed = \Dashed\DashedFiles\Classes\Legacy\LegacyClassAliases::packageInstalled();
        $files = self::filesNamingLegacyClasses(base_path());

        $detail = match (true) {
            $installed => 'the old package is still installed, so the aliases stay idle',
            ! $enabled && $files !== [] => 'aliases are switched off while project files still name the old classes: those files fail',
            $files !== [] => 'project files still name the old classes; they work through the aliases, dashed:remove-legacy-media-library --code --write tidies them up',
            default => null,
        };

        return $this->result('legacy_aliases', 'Old media library class names', [
            'enabled' => $enabled,
            // Actief: het pakket is weg en de schakelaar staat aan, dus een oude naam wordt ons model.
            'active' => $enabled && ! $installed,
            'package_installed' => $installed,
            'aliased_in_this_process' => array_keys(\Dashed\DashedFiles\Classes\Legacy\LegacyClassAliases::aliased()),
            'files_naming_old_classes' => count($files),
            'files' => array_slice($files, 0, 20),
        ], $enabled || $files === [], $detail);
    }

    /**
     * De bestanden onder app/, database/ en resources/views/ die een van de
     * oude klassenamen noemen, relatief aan de projectmap.
     *
     * @return array<int, string>
     */
    public static function filesNamingLegacyClasses(string $root): array
    {
        $root = rtrim($root, '/');
        $directories = array_values(array_filter(array_map(fn (string $dir) => $root . '/' . $dir, self::LEGACY_SCAN_DIRECTORIES), 'is_dir'));

        if ($directories === []) {
            return [];
        }

        $hits = [];
        $finder = \Symfony\Component\Finder\Finder::create()->files()->in($directories)->name('*.php')
            ->exclude(['vendor', 'node_modules', 'storage'])->ignoreVCS(true)
            ->contains(\Dashed\DashedFiles\Classes\Legacy\LegacyClassAliases::PREFIX);

        foreach ($finder as $file) {
            $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');

            foreach (array_keys(\Dashed\DashedFiles\Classes\Legacy\LegacyClassAliases::MAP) as $name) {
                if (stripos((string) $file->getContents(), $name) !== false) {
                    $hits[] = $relative;

                    break;
                }
            }
        }

        sort($hits);

        return array_values(array_unique($hits));
    }

    /**
     * Wat code (een taak, commando, de scheduler) in de prullenbak gooide en
     * na `dashed-files.trash.code_deleted_purge_days` vanzelf weggaat, en
     * hoeveel daarvan binnen een week. Altijd in orde: dit is een telling.
     */
    protected function checkCodeTrash(): array
    {
        if (! \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasTrashOrigin()) {
            return $this->result('code_trash', 'Items trashed by code', null, true, 'column trashed_via missing: run the migrations');
        }

        $days = \Dashed\DashedFiles\Classes\Browse\MediaTrashOrigin::codePurgeDays();
        $base = fn () => DB::table(self::ITEMS)->whereNotNull('deleted_at')->where('trashed_via', \Dashed\DashedFiles\Classes\Browse\MediaTrashOrigin::CODE);
        $total = $base()->count();
        $soon = $days === null ? 0 : $base()->where('deleted_at', '<', now()->subDays($days)->addDays(7))->count();

        return $this->result('code_trash', 'Items trashed by code', ['total' => $total, 'purged_within_7_days' => $soon, 'purge_days' => $days], true, match (true) {
            $days === null => 'automatic purge of code-trashed items is off (dashed-files.trash.code_deleted_purge_days = 0)',
            $total > 0 => 'purged after ' . $days . ' days unless still served, referenced, merged or a poster',
            default => null,
        });
    }

    /**
     * Fase 11: de externe bronnen. Per aangemelde bron of hij op deze site
     * te gebruiken is en wat er aan- of uitstaat. Zonder een aanroep naar
     * buiten, en van een sleutel alleen of hij er is. Altijd in orde: een
     * bron die uitstaat is een keuze, geen storing.
     */
    protected function checkSources(): array
    {
        $registry = app(\Dashed\DashedFiles\Classes\Sources\MediaSourceRegistry::class);
        $siteId = $registry::siteId();
        $parts = [];
        $details = [];

        foreach ($registry->all() as $key => $source) {
            $available = (bool) rescue(fn () => $source->isAvailable($siteId), false, false);
            $parts[] = $key . ' ' . ($available ? 'available' : 'off');

            $facts = [];

            foreach ((array) rescue(fn () => $source->diagnostics($siteId), [], false) as $name => $value) {
                // Alleen ja/nee en getallen: nooit een sleutel of een andere tekst uit de instellingen.
                if (is_bool($value)) {
                    $facts[] = $name . ' ' . ($value ? 'yes' : 'no');
                } elseif (is_int($value)) {
                    $facts[] = $name . ' ' . $value;
                }
            }

            $details[] = $key . ' (' . ($facts === [] ? '-' : implode(', ', $facts)) . ')';
        }

        return $this->result(
            'sources',
            'External sources',
            $parts === [] ? 'none registered' : implode(', ', $parts),
            true,
            $details === [] ? null : 'site ' . ($siteId ?? '-') . ': ' . implode('; ', $details)
        );
    }

    /**
     * Fase 10: de schijf voor privé-bestanden. Een probleem als hij naar
     * dezelfde plek wijst als de publieke schijf, als een lokale map onder
     * een publiek uitgeserveerde map valt, of als er privé-bestanden zijn en
     * er niet op te schrijven valt. Zonder privé-mappen wordt de schijf niet
     * aangeraakt: dan is er nog niets dat hem nodig heeft.
     */
    protected function checkPrivateDisk(): array
    {
        if (! \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasAccess()) {
            return $this->result('private_disk', 'Private media disk', null, true, 'the columns do not exist yet: run the migrations');
        }

        $inUse = $this->privateInUse();
        $disk = \Dashed\DashedFiles\Classes\Access\MediaStorage::inspect(probe: $inUse);

        if (! $disk['configured']) {
            return $this->result('private_disk', 'Private media disk', $disk['disk'] . ' (not configured)', ! $inUse, 'the public disk `dashed` is not configured either, so nothing could be derived');
        }

        $value = $disk['disk'] . ' (' . $disk['driver'] . ', visibility ' . $disk['visibility'] . ($inUse ? ', ' . ($disk['writable'] ? 'writable' : 'NOT writable') : ', not checked: no private folders') . ')';
        $detail = match (true) {
            $disk['same_as_public'] => 'points to the same place as the public disk: private files would be public',
            $disk['outside_public'] === false => 'the local folder ' . $disk['local_root'] . ' is inside a folder the web server serves',
            $disk['visibility'] !== 'private' => 'the disk is not private: files written to it are public',
            $inUse && ! $disk['writable'] => 'cannot write to the disk' . ($disk['error'] ? ': ' . $disk['error'] : ''),
            $disk['local_root'] !== null => 'local folder ' . $disk['local_root'] . ', outside the public folders',
            default => null,
        };

        $ok = ! $disk['same_as_public'] && $disk['outside_public'] !== false && $disk['visibility'] === 'private' && (! $inUse || $disk['writable']);

        return $this->result('private_disk', 'Private media disk', $value, $ok, $detail);
    }

    protected function checkPrivateItems(): array
    {
        if (! \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasAccess()) {
            return $this->result('private_items', 'Private items', null);
        }

        $folders = count(app(\Dashed\DashedFiles\Classes\Access\MediaVisibility::class)->privateFolderIds());

        return $this->result('private_items', 'Private items', (int) DB::table('filament_media_library')->where('is_private', true)->count(), true, $folders . ' private folders (own or inherited)');
    }

    /** Items waarvan de bestanden nog van of naar de privé-schijf moeten. Ze blijven tot dan afgeschermd. */
    protected function checkPrivateRelocating(): array
    {
        if (! \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasAccess()) {
            return $this->result('private_relocating', 'Items waiting to move between the disks', null);
        }

        $visibility = app(\Dashed\DashedFiles\Classes\Access\MediaVisibility::class);
        $pending = $visibility->pendingCount();
        $gaveUp = $visibility->givenUpIds();
        $late = $visibility->waitingLongerThan(3600);
        $tooLarge = $this->tooLargeToRelocateInQueue();

        // Rood zodra een item is opgegeven, langer dan een uur wacht of te
        // groot is voor de wachtrijtaak: dan komt het niet vanzelf goed.
        $problems = array_filter([
            $gaveUp === [] ? null : 'gave up: ' . implode(', ', $gaveUp),
            $late === [] ? null : 'waiting longer than an hour: ' . implode(', ', $late),
            $tooLarge === [] ? null : 'a file larger than the queue job copies (' . (int) (\Dashed\DashedFiles\Classes\Access\MediaRelocator::queueMaxBytes() / 1024 / 1024) . ' MB, dashed-files.private.queue_max_mb): ' . implode(', ', $tooLarge),
        ]);

        $detail = match (true) {
            $problems !== [] => implode('; ', $problems) . '. They stay closed; run dashed:media-private relocate --now --force to retry',
            $pending > 0 => 'they stay closed until moved; the minute task moves them',
            default => null,
        };

        return $this->result('private_relocating', 'Items waiting to move between the disks', $pending, $problems === [], $detail);
    }

    /**
     * Wachtende items met een bestand boven de grens van de wachtrijtaak: die
     * slaat ze over, dus alleen --now verhuist ze.
     *
     * @return array<int, int>
     */
    private function tooLargeToRelocateInQueue(): array
    {
        $max = \Dashed\DashedFiles\Classes\Access\MediaRelocator::queueMaxBytes();

        if ($max <= 0) {
            return [];
        }

        return DB::table(self::ITEMS . ' as item')
            ->join('media', fn ($join) => $join->on('media.model_id', '=', 'item.id')->whereIn('media.model_type', $this->libraryMorphTypes()))
            ->whereNotNull('item.relocate_to')
            ->where('media.size', '>', $max)
            ->distinct()
            ->orderBy('item.id')
            ->limit(50)
            ->pluck('item.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Bestanden die als dubbel kandidaat staan maar te groot zijn voor de
     * wachtrijtaak die vingerafdrukken maakt: die slaat ze over, dus zonder
     * `dashed:media-checksums --now` vindt de dubbelcontrole ze nooit.
     * Informatief: er gaat niets mis, er wordt alleen iets niet gevonden.
     */
    protected function checkChecksumsTooLargeForQueue(): array
    {
        $label = 'Files too large to fingerprint on the queue';
        $max = \Dashed\DashedFiles\Jobs\HashMediaItems::queueMaxBytes();

        if ($max <= 0 || ! Schema::hasColumn(self::ITEMS, 'checksum') || ! Schema::hasColumn(self::ITEMS, 'size')) {
            return $this->result('checksums_too_large', $label, null);
        }

        $count = DB::table(self::ITEMS)->whereNull('checksum')->where('size', '>', $max)->count();

        return $this->result('checksums_too_large', $label, $count, true, $count === 0 ? null : 'larger than ' . (int) ($max / 1024 / 1024) . ' MB (dashed-files.ingest.queue_hash_max_mb); run dashed:media-checksums --now');
    }

    /**
     * Een open item in een privé-map (eigen of geërfd) is een lek: de site
     * kan het tonen terwijl de beheerder denkt dat het dicht is. De
     * minuuttaak zet zo'n item dicht; staat het hier, dan draait die niet.
     */
    protected function checkPrivateOpenItems(): array
    {
        if (! \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasAccess()) {
            return $this->result('private_open_items', 'Open items in a private folder', null);
        }

        $open = app(\Dashed\DashedFiles\Classes\Access\MediaVisibility::class)->openItemsInPrivateFolders();

        return $this->result('private_open_items', 'Open items in a private folder', count($open), $open === [], $open === [] ? null : 'ids: ' . implode(', ', $open) . '. Run dashed:media-private relocate --now to close them');
    }

    /**
     * De proef op de som: is een bestand op de privé-schijf zonder inloggen
     * op te halen? Alleen als de schijf een adres kan maken (S3, of lokaal
     * met een url) en er privé-bestanden zijn. Schrijft een proefbestand met
     * een willekeurige naam, vraagt het anoniem op en ruimt het altijd weer op.
     */
    protected function checkPrivateDiskAnonymous(): array
    {
        $label = 'Anonymous access to the private disk';

        if (! \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasAccess() || ! $this->privateInUse()) {
            return $this->result('private_disk_anonymous', $label, null, true, 'not checked: no private folders');
        }

        $probe = \Dashed\DashedFiles\Classes\Access\MediaStorage::probeAnonymous();

        return match ($probe['status']) {
            'open' => $this->result('private_disk_anonymous', $label, 'OPEN', false, 'an anonymous request for a test file on the private disk answered 200: private files are public. Check the bucket policy or the web server'),
            'closed' => $this->result('private_disk_anonymous', $label, 'refused (' . $probe['detail'] . ')', true),
            default => $this->result('private_disk_anonymous', $label, null, true, 'not checked: ' . $probe['detail']),
        };
    }

    private function privateInUse(): bool
    {
        return DB::table('filament_media_library')->where('is_private', true)->exists()
            || DB::table('filament_media_library_folders')->where('is_private', true)->exists();
    }

    /**
     * Items waar de tekstkolom (alt-tekst, bijschrift, titel) afwijkt van de
     * eerste taal in de vertalingen: iets schreef langs de haken heen.
     */
    protected function checkTextMirror(): array
    {
        if (! \Dashed\DashedFiles\Classes\MediaTexts::hasColumns()) {
            return $this->result('text_mirror', 'Texts out of sync with their first locale', null, true, 'the translation columns do not exist yet: run the migrations');
        }

        $drifted = app(\Dashed\DashedFiles\Classes\MediaLibrary::class)->syncTexts(write: false);

        return $this->result('text_mirror', 'Texts out of sync with their first locale', $drifted, true, $drifted > 0 ? 'run dashed:media-texts --sync' : null);
    }

    /**
     * De vastgelegde spiegeltaal: de taal waar de tekstkolommen de spiegel
     * van zijn. Wijkt die af van de eerste taal van de site, dan is er na de
     * migratie een taal toegevoegd of de volgorde gewijzigd. Dat is geen
     * storing (er wordt niets overschreven), maar wie de nieuwe eerste taal
     * in de tekstkolommen wil moet bewust wisselen.
     */
    protected function checkMirrorLocale(): array
    {
        $texts = \Dashed\DashedFiles\Classes\MediaTexts::class;
        $mirror = $texts::firstLocale();
        $live = $texts::liveFirstLocale();
        $detail = null;

        if ($texts::storedMirrorLocale() === null) {
            $detail = 'not stored yet';
        } elseif (! $texts::isLocale($mirror)) {
            $detail = 'WARNING: this is no longer an active locale; dashed:media-texts --sync refuses until you switch with dashed:media-texts --mirror=<locale>';
        } elseif ($live !== null && $live !== $mirror) {
            $detail = 'WARNING: the first locale of the site is now ' . $live . '; the text columns stay ' . $mirror . ' until you run dashed:media-texts --mirror=' . $live;
        }

        return $this->result('text_mirror_locale', 'Mirror locale of alt text, caption and title', $mirror, true, $detail);
    }

    /**
     * De schakelaar "alt-tekst bij nieuwe uploads" van de AI-instellingen.
     * Die werd lange tijd nergens gelezen; nu start elke upload in het beheer
     * er een betaalde AI-aanroep mee.
     */
    protected function checkAltTextOnUpload(): array
    {
        $on = (bool) rescue(fn () => \Dashed\DashedCore\Models\Customsetting::get('create_alt_text_for_new_uploaded_images'), false, false);

        return $this->result(
            'alt_text_on_upload',
            'Alt text for new uploads (AI)',
            $on ? 'on' : 'off',
            true,
            $on ? 'WARNING: every image uploaded in the admin or the app is one paid AI call (all locales in one call); imports and chat attachments are skipped' : null,
        );
    }

    /** Of de lijst "waar gebruikt" gevuld is; zonder is er geen "ongebruikt" en geen samenvoegen. */
    protected function checkUsageIndex(): array
    {
        $indexer = app(\Dashed\DashedFiles\Classes\Usage\MediaUsageIndexer::class);

        if (! \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasUsages()) {
            return $this->result('usage_index', 'Usage index', null, true, 'the table does not exist yet: run the migrations');
        }

        return $this->result(
            'usage_index',
            'Usage index (references)',
            DB::table(\Dashed\DashedFiles\Classes\Browse\BrowseSchema::USAGES)->count(),
            true,
            $indexer->isReady() ? null : 'not built yet: run dashed:media-usages',
        );
    }

    /**
     * Bloktypen waarvan het schema bij de laatste indexronde niet te bouwen
     * was. Hun verwijzingen zijn gegokt: goed genoeg om een bestand uit
     * "Ongebruikt" te houden, niet om iets op te herschrijven.
     */
    protected function checkUnbuildableBlocks(): array
    {
        $types = array_keys(app(\Dashed\DashedFiles\Classes\Usage\MediaUsageIndexer::class)->unbuildable());

        return $this->result(
            'unbuildable_blocks',
            'Blocks without a buildable schema',
            $types === [] ? 0 : $types,
            true,
            $types === [] ? null : 'media references in these blocks are guessed; see dashed:media-usages --audit',
        );
    }

    protected function checkItems(): array
    {
        return $this->result('items', 'Items', DB::table(self::ITEMS)->count());
    }

    protected function checkFolders(): array
    {
        return $this->result('folders', 'Folders', DB::table(self::FOLDERS)->count());
    }

    protected function checkItemsWithoutMedia(): array
    {
        $count = DB::table(self::ITEMS . ' as items')
            ->whereNotExists(fn (Builder $query) => $query
                ->from('media')
                ->whereColumn('media.model_id', 'items.id')
                ->whereIn('media.model_type', $this->libraryMorphTypes()))
            ->count();

        return $this->result('items_without_media', 'Items without a media row', $count, $count === 0);
    }

    protected function checkMediaByModelType(): array
    {
        $perType = DB::table('media')
            ->selectRaw('model_type, count(*) as aggregate')
            ->groupBy('model_type')
            ->orderBy('model_type')
            ->pluck('aggregate', 'model_type')
            ->map(fn ($count): int => (int) $count)
            ->all();

        // Alleen rijen van de bibliotheek: media van andere modellen horen
        // niet onder de alias te staan.
        $legacy = $perType[MediaItem::LEGACY_CLASS] ?? 0;

        return $this->result('media_by_model_type', 'Media rows per model type', $perType, true, $legacy . ' library rows not under the alias ' . MediaItem::MORPH_ALIAS);
    }

    protected function checkMediaUnderLegacyClass(): array
    {
        // De relatie en de scope van het item zoeken op de alias. Een rij
        // onder de volle klassenaam wordt dus niet gevonden en het item
        // verdwijnt uit de bibliotheek en van de site.
        $count = DB::table('media')->where('model_type', MediaItem::LEGACY_CLASS)->count();

        return $this->result(
            'media_under_legacy_class',
            'Library media rows under the full package class name',
            $count,
            $count === 0,
            $count === 0 ? null : 'these files are invisible: normalise media.model_type to the alias ' . MediaItem::MORPH_ALIAS,
        );
    }

    protected function checkMissingConversions(): array
    {
        // Dezelfde query als de pomp: een `missing`-run maakt precies deze formaten.
        $missing = app(\Dashed\DashedFiles\Classes\Conversions\ConversionPump::class)->countMissingRequested();

        $detail = $missing > self::MISSING_CONVERSIONS_WARNING
            ? sprintf('WARNING: more than %d, each one is queued for generation the first time it is requested', self::MISSING_CONVERSIONS_WARNING)
            : null;

        // Informatief: een ontbrekend formaat is geen fout, het wordt op aanvraag gemaakt.
        return $this->result('missing_conversions', 'Requested formats that are not generated yet', $missing, true, $detail);
    }

    protected function checkOrphanedMedia(): array
    {
        $count = DB::table('media')
            ->whereIn('model_type', $this->libraryMorphTypes())
            ->whereNotExists(fn (Builder $query) => $query
                ->from(self::ITEMS)
                ->whereColumn(self::ITEMS . '.id', 'media.model_id'))
            ->count();

        return $this->result('orphaned_media', 'Library media rows without an item', $count, $count === 0);
    }

    protected function checkItemsWithMissingFolder(): array
    {
        $count = DB::table(self::ITEMS . ' as items')
            ->whereNotNull('items.folder_id')
            ->whereNotExists(fn (Builder $query) => $query
                ->from(self::FOLDERS)
                ->whereColumn(self::FOLDERS . '.id', 'items.folder_id'))
            ->count();

        return $this->result('items_with_missing_folder', 'Items in a folder that does not exist', $count, $count === 0);
    }

    protected function checkQueuedJobs(): array
    {
        $connection = (string) config('queue.default');

        // Alleen de database-wachtrij is hier te doorzoeken. Bij Redis of
        // sync zegt een lege jobs-tabel niets, dus geen OK op basis daarvan.
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return $this->result('queued_jobs', 'Queued jobs that reference the package', null, true, "not measured: the queue connection is {$connection}, not the database");
        }

        return $this->queueTableCheck('queued_jobs', 'Queued jobs that reference the package', (string) config("queue.connections.{$connection}.table", 'jobs'));
    }

    protected function checkFailedJobs(): array
    {
        return $this->queueTableCheck('failed_jobs', 'Failed jobs that reference the package', (string) config('queue.failed.table', 'failed_jobs'));
    }

    protected function checkImageDriver(): array
    {
        $driver = (string) config('media-library.image_driver', 'gd');
        $loaded = extension_loaded($driver);

        return $this->result('image_driver', 'Image driver', $driver, $loaded, $loaded ? null : 'PHP extension is not loaded');
    }

    /** Wat de server met beeld kan. Altijd OK: het is een opsomming, geen oordeel. */
    protected function checkCapabilities(): array
    {
        $summary = \Dashed\DashedFiles\Classes\MediaCapabilities::summary();
        $yesNo = fn (bool $value): string => $value ? 'yes' : 'no';

        return $this->result('capabilities', 'Image capabilities', sprintf(
            'driver %s, webp %s, avif %s, ffmpeg %s, ghostscript %s, exec %s',
            $summary['driver'],
            $yesNo($summary['webp']),
            $yesNo($summary['avif']),
            $summary['ffmpeg'] ?? 'not found',
            $summary['ghostscript'] ?? 'not found',
            $yesNo($summary['exec']),
        ), true, 'AVIF setting: ' . (\Dashed\DashedFiles\Classes\MediaLibrary::avifEnabled() ? 'on' : 'off'));
    }

    /**
     * Wat de server met video en pdf kan. Altijd in orde: zonder de
     * programma's valt de bibliotheek terug op wat PHP zelf leest en op de
     * poster uit de browser. Gevonden is nog niet "start": op gedeelde
     * hosting kan het starten zelf geweigerd worden, vandaar de proef.
     */
    protected function checkMediaProcessing(): array
    {
        $capabilities = \Dashed\DashedFiles\Classes\MediaCapabilities::class;
        $binary = fn (?string $path, string $name): string => $path === null ? 'not found' : $path . ($capabilities::canRun($name) ? ' (starts)' : ' (does not start)');
        $previews = app(\Dashed\DashedFiles\Classes\Preview\MediaPreviews::class);
        $previewClass = \Dashed\DashedFiles\Classes\Preview\MediaPreviews::class;
        $gsEnabled = \Dashed\DashedFiles\Classes\Preview\GhostscriptPreview::enabled();
        $gs = $capabilities::ghostscript();
        $sync = $previewClass::queueIsSync();
        $ffmpeg = $previews->generatorFor('video/mp4') !== null;

        $detail = 'video posters: ' . match (true) {
            ! $ffmpeg => 'made by the browser at upload; duration and size from the mp4 header',
            $sync => 'made by the browser at upload, because the queue is sync: without a worker an upload starts no ffmpeg in the web process, only php reads the mp4 header after the response. Run dashed:media-previews --missing (cron or by hand) or use "Poster opnieuw maken" for a poster from ffmpeg',
            default => 'server (ffmpeg)',
        };
        $detail .= '; pdf thumbnails: ' . match (true) {
            $previews->generatorFor('application/pdf') !== null => $sync ? 'server (ghostscript), through dashed:media-previews --missing because the queue is sync' : 'server (ghostscript)',
            $gs !== null && ! $gsEnabled => 'none, ghostscript is found but disabled (set DASHED_FILES_GHOSTSCRIPT=true to turn it on); page count where readable',
            default => 'none; page count where readable',
        };
        $detail .= '; files from the chat or the api, or without a known source, never go to an external program';

        return $this->result('media_processing', 'Media processing (video and pdf)', sprintf(
            'ffmpeg %s, ffprobe %s, ghostscript %s',
            $binary($capabilities::ffmpeg(), 'ffmpeg'),
            $binary($capabilities::ffprobe(), 'ffprobe'),
            $gs !== null && ! $gsEnabled ? $gs . ' (found but disabled)' : $binary($gs, 'gs'),
        ), true, $detail);
    }

    /**
     * Video's en pdf's zonder voorvertoning of die nog nooit bekeken zijn.
     * Informatief: zonder ffmpeg of ghostscript is "geen voorvertoning" de
     * verwachte stand.
     */
    protected function checkPreviews(): array
    {
        if (! \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasPreview()) {
            return $this->result('previews', 'Videos and pdfs without preview or metadata', null, true, 'the preview columns do not exist yet: run the migrations');
        }

        $previews = app(\Dashed\DashedFiles\Classes\Preview\MediaPreviews::class);
        $noPreview = fn (string $kind): int => $previews->kindQuery($kind)
            ->whereDoesntHave('media', fn ($media) => $media->where('collection_name', MediaItem::COLLECTION_PREVIEW))
            ->whereNull('filament_media_library.poster_item_id')
            ->count();

        $value = [
            'videos' => $previews->kindQuery('video')->count(),
            'videos_without_poster' => $noPreview('video'),
            'videos_without_duration' => $previews->kindQuery('video')->whereNull('filament_media_library.duration')->count(),
            'pdfs' => $previews->kindQuery('pdf')->count(),
            'pdfs_without_thumbnail' => $noPreview('pdf'),
            'pdfs_without_page_count' => $previews->kindQuery('pdf')->whereNull('filament_media_library.pages')->count(),
            'never_looked_at' => $previews->kindQuery()->whereNull('filament_media_library.preview_checked_at')->count(),
        ];

        return $this->result('previews', 'Videos and pdfs without preview or metadata', $value, true, $value['never_looked_at'] > 0 ? 'run dashed:media-previews --missing' : (($value['videos_without_poster'] + $value['pdfs_without_thumbnail']) > 0 ? 'already looked at; dashed:media-previews --missing --retry tries them again' : null));
    }

    /**
     * SVG's zonder de vlag van de opschoner (of van een oudere versie). Op
     * een lokale publieke schijf zonder de header-regel is dat een probleem:
     * zo'n bestand kan, rechtstreeks geopend, een script uitvoeren.
     */
    protected function checkUnsanitizedSvg(): array
    {
        $backlog = \Dashed\DashedFiles\Classes\Svg\SvgBacklog::class;
        $count = app($backlog)->countUnsanitized();
        $local = $backlog::diskIsLocalPublic();
        $header = $local && $backlog::headerPresent();

        return $this->result(
            'unsanitized_svg',
            'Svg files that were never sanitized',
            $count,
            ! ($count > 0 && $local && ! $header),
            ($count > 0 ? 'run dashed:media-sanitize-svg' : null)
                ?? ($local ? ('local public disk, svg header rule ' . ($header ? 'present' : 'missing: run dashed:media-sanitize-svg (Apache) or add the header in nginx')) : null),
        );
    }

    /** De pomp: een run die mislukt is blijft staan tot iemand hem hervat. */
    protected function checkConversionRuns(): array
    {
        if (! \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasRuns()) {
            return $this->result('conversion_runs', 'Format runs', null, true, 'the table does not exist yet: run the migrations');
        }

        $perStatus = DB::table(\Dashed\DashedFiles\Classes\Browse\BrowseSchema::RUNS)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        return $this->result('conversion_runs', 'Format runs per status', $perStatus === [] ? 0 : $perStatus, true, ($perStatus['failed'] ?? 0) > 0 ? 'failed runs can be resumed with dashed:media-conversions resume' : null);
    }

    /**
     * Bestanden die de pomp heeft opgegeven: twee keer mislukt of afgebroken.
     * Elke run slaat ze over tot het bestand wijzigt of iemand ze met een
     * `force` opnieuw vraagt. Informatief: het is geen fout van de installatie.
     */
    protected function checkGivenUpConversions(): array
    {
        $items = app(\Dashed\DashedFiles\Classes\Conversions\ConversionPump::class)->givenUp();

        return $this->result(
            'conversions_given_up',
            'Items the format runs gave up on',
            $items === [] ? 0 : $items,
            true,
            $items === [] ? null : 'skipped by every run; retry one with dashed:media-conversions force --ids=<id>',
        );
    }

    protected function queueTableCheck(string $key, string $label, string $table): array
    {
        if (! Schema::hasTable($table)) {
            return $this->result($key, $label, 0, true, "table {$table} does not exist");
        }

        $total = DB::table($table)->count();

        // Alleen het nieuwste deel: de rest van een volgelopen tabel is oud werk.
        $recent = DB::table($table)->select('payload')->orderByDesc('id')->limit(self::QUEUE_SCAN_LIMIT);
        $count = DB::query()->fromSub($recent, 'recent')->where('payload', 'like', '%' . self::PACKAGE_NEEDLE . '%')->count();

        $detail = $total > self::QUEUE_SCAN_LIMIT
            ? sprintf('table too large to scan in full: only the newest %d of %d rows were checked', self::QUEUE_SCAN_LIMIT, $total)
            : null;

        return $this->result($key, $label, $count, $count === 0, $detail);
    }

    /**
     * @return array<int, string>
     */
    protected function libraryMorphTypes(): array
    {
        return [MediaItem::MORPH_ALIAS, MediaItem::LEGACY_CLASS];
    }

    /**
     * @return array{key: string, label: string, value: mixed, ok: bool, detail: ?string}
     */
    protected function result(string $key, string $label, mixed $value, bool $ok = true, ?string $detail = null): array
    {
        return compact('key', 'label', 'value', 'ok', 'detail');
    }
}
