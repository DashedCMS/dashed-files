<?php

namespace Dashed\DashedFiles\Classes;

use Illuminate\Support\Facades\DB;
use Dashed\DashedFiles\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Dashed\DashedFiles\Classes\Browse\MediaItemQuery;

/**
 * Wat nooit naar een externe dienst (een AI-provider) mag: een bijlage die
 * een bezoeker in de chat stuurde (bron `chat`) en alles in de mappen `chat`
 * en `orders`, de bijlagen bij een gesprek of een bestelling. Dat zijn
 * bestanden van klanten, geen inhoud van de site. En alles wat privé is
 * (fase 10): een bestand in een privé-map, of een bestand dat nog van of
 * naar de privé-schijf onderweg is.
 *
 * Eén definitie, voor de lijstquery van een bulkronde én voor de toets op
 * één item vlak voor het versturen.
 */
final class MediaPrivacy
{
    public const SOURCES = ['chat'];

    public const FOLDERS = MediaItemQuery::PROTECTED_FOLDERS;

    public static function isProtected(MediaItem $item): bool
    {
        // Privé gaat nooit naar buiten, ook niet als het item pas na het klaarzetten van een taak privé werd.
        if (Access\MediaVisibility::isPrivate($item)) {
            return true;
        }


        $source = $item->getAttribute('source') ?: rescue(fn () => $item->file()?->getCustomProperty('source'), null, false);

        if (is_string($source) && in_array(strtolower($source), self::SOURCES, true)) {
            return true;
        }

        $folderId = $item->getAttribute('folder_id');

        return $folderId !== null && in_array((int) $folderId, self::folderIds(), true);
    }

    /**
     * Of een adres naar een beschermd bestand van de mediabibliotheek wijst.
     *
     * Herkent de publieke URL van de schijf `dashed` en van de privé-schijf
     * (origineel, formaat of responsive, met of zonder querystring) en de
     * ondertekende privé-route van het beheer, zoekt het item erbij en geeft
     * isProtected() terug. Een adres dat niet naar de bibliotheek wijst geeft
     * false: een extern adres mag een beheerder bewust plakken. Een adres op
     * onze schijf waar geen item bij te vinden is geeft true, net als een
     * fout in de toets: bij twijfel gaat er niets naar buiten.
     *
     * Kost een paar query's per adres. Bedoeld voor een losse actie vlak
     * voor het versturen, niet voor een lijst of een tegel.
     */
    public static function isProtectedUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || preg_match('#^(?:data|blob):#i', $url)) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        // Geen host en geen pad vanaf de wortel: geen adres.
        if ($host === '' && ! str_starts_with($path, '/')) {
            return false;
        }

        return (bool) rescue(function () use ($host, $path) {
            if (self::isOwnHost($host) && preg_match('#/media/private/(\d+)(?:/|$)#', $path, $match)) {
                $item = MediaItem::withoutGlobalScopes()->find((int) $match[1]);

                return $item === null || self::isProtected($item);
            }

            foreach (array_unique([Access\MediaStorage::publicDisk(), Access\MediaStorage::privateDisk()]) as $disk) {
                $relative = self::relativeToDisk($disk, $host, $path);

                if ($relative !== null) {
                    return self::isProtectedFile($relative);
                }
            }

            return false;
        }, true, false);
    }

    /** Het pad binnen de schijf als het adres op die schijf wijst, anders null. */
    private static function relativeToDisk(string $disk, string $host, string $path): ?string
    {
        $base = rescue(fn () => (string) \Illuminate\Support\Facades\Storage::disk($disk)->url(''), '', false);

        if ($base === '') {
            return null;
        }

        $baseParts = parse_url($base) ?: [];
        $baseHost = strtolower((string) ($baseParts['host'] ?? ''));
        $basePath = rtrim((string) ($baseParts['path'] ?? ''), '/');

        $sameHost = $baseHost !== '' ? $baseHost === $host : self::isOwnHost($host);

        if (! $sameHost || ! str_starts_with($path, $basePath . '/')) {
            return null;
        }

        return substr($path, strlen($basePath) + 1);
    }

    /** `{uuid}/{bestand}` of `{uuid}/conversions|responsive/{bestand}`. */
    private static function isProtectedFile(string $relative): bool
    {
        $segments = array_values(array_filter(explode('/', rawurldecode($relative)), fn ($segment) => $segment !== ''));
        $uuid = $segments[0] ?? '';

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return true;
        }

        $mediaModel = config('media-library.media_model') ?: \Spatie\MediaLibrary\MediaCollections\Models\Media::class;
        $media = $mediaModel::query()->where('uuid', $uuid)->first();

        if ($media === null) {
            return true;
        }

        $isOriginal = count($segments) === 2 && $segments[1] === $media->file_name;
        $isDerived = count($segments) === 3 && in_array($segments[1], ['conversions', 'responsive'], true);

        if (! $isOriginal && ! $isDerived) {
            return true;
        }

        if (Access\MediaStorage::isPrivateDisk($media->disk) || Access\MediaStorage::isPrivateDisk($media->conversions_disk)) {
            return true;
        }

        $type = (string) $media->model_type;
        $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type) ?? $type;

        if ($type !== MediaItem::MORPH_ALIAS && ! is_a($class, MediaItem::class, true) && ! str_ends_with($type, '\\MediaLibraryItem')) {
            return true;
        }

        $item = MediaItem::withoutGlobalScopes()->find((int) $media->model_id);

        return $item === null || self::isProtected($item);
    }

    /** Leeg (een pad zonder host), het adres van de app of de host van dit verzoek. */
    private static function isOwnHost(string $host): bool
    {
        if ($host === '') {
            return true;
        }

        $own = array_filter([
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            app()->bound('request') ? strtolower((string) request()->getHost()) : '',
        ]);

        return in_array($host, $own, true);
    }

    /** Laat uit een query op de itemtabel alles weg wat beschermd is. */
    public static function exclude(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        if (BrowseSchema::hasMirror()) {
            $query->where(fn (Builder $source) => $source->whereNull($table . '.source')->orWhereNotIn($table . '.source', self::SOURCES));
        }

        if (BrowseSchema::hasAccess()) {
            $query->where($table . '.is_private', false);
        }

        $folders = self::folderIds();

        if ($folders !== []) {
            $query->where(fn (Builder $folder) => $folder->whereNull($table . '.folder_id')->orWhereNotIn($table . '.folder_id', $folders));
        }

        return $query;
    }

    /**
     * De beschermde mappen in de hoofdmap met alles eronder. Per verzoek of
     * taak één keer opgezocht.
     *
     * @return array<int, int>
     */
    public static function folderIds(): array
    {
        $request = app()->bound('request') ? request() : null;
        $memo = $request?->attributes->get('dashed-files.protected-folders');

        if (is_array($memo)) {
            return $memo;
        }

        $ids = rescue(function () {
            $table = 'filament_media_library_folders';
            $roots = DB::table($table)->whereNull('parent_id')->whereIn(DB::raw('LOWER(name)'), self::FOLDERS)->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($roots === []) {
                return [];
            }

            $all = $roots;

            if (BrowseSchema::hasPaths()) {
                // Het pad bestaat alleen uit cijfers en schuine strepen.
                $all = array_merge($all, DB::table($table)->where(function ($paths) use ($roots) {
                    foreach ($roots as $root) {
                        $paths->orWhere('path', 'like', '/' . $root . '/%');
                    }
                })->pluck('id')->map(fn ($id) => (int) $id)->all());
            } else {
                // Zonder padkolom: laag voor laag naar beneden.
                $layer = $roots;

                for ($depth = 0; $depth < 20 && $layer !== []; $depth++) {
                    $layer = DB::table($table)->whereIn('parent_id', $layer)->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $all = array_merge($all, $layer);
                }
            }

            return array_values(array_unique($all));
        }, [], false);

        $request?->attributes->set('dashed-files.protected-folders', $ids);

        return $ids;
    }

    public static function forget(): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove('dashed-files.protected-folders');
        }
    }
}
