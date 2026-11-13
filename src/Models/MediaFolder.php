<?php

namespace Dashed\DashedFiles\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaFolder extends Model
{
    // Het model van het oude pakket kent deleted_at niet en toont op de motor `legacy` ook een weggegooide map.
    use SoftDeletes;

    protected $table = 'filament_media_library_folders';

    protected $fillable = [
        'name',
        'parent_id',
        'trash_batch',
        'site_ids',
        'is_private',
    ];

    protected $casts = [
        'site_ids' => 'array',
        'is_private' => 'boolean',
    ];

    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new \Dashed\DashedFiles\Models\Scopes\MediaTrashScope());
    }

    protected static function booted(): void
    {
        // Terugzetten buiten de beheerpagina om (de prullenbak van dashed-core):
        // ook dan komt mee wat in dezelfde handeling is weggegooid.
        static::restoring(function (self $folder) {
            $batch = $folder->trash_batch;
            $folder->trash_batch = null;

            if (filled($batch)) {
                static::onlyTrashed()->toBase()->where('trash_batch', $batch)->where('id', '!=', $folder->getKey())->update(['deleted_at' => null, 'trash_batch' => null]);
                MediaItem::withoutGlobalScopes()->toBase()->where('trash_batch', $batch)->whereNotNull('deleted_at')->update(array_merge(
                    ['deleted_at' => null, 'deleted_by_user_id' => null, 'trash_batch' => null],
                    \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasServed() ? ['trashed_served_at' => null] : [],
                    \Dashed\DashedFiles\Classes\Browse\BrowseSchema::hasTrashOrigin() ? ['trashed_via' => null] : [],
                ));
            }

            // De ouder kan intussen weg zijn of zelf in de prullenbak liggen
            // (na de update hierboven is een ouder uit dezelfde partij al
            // terug): dan komt de map op het hoogste niveau.
            if ($folder->parent_id !== null && ! static::query()->whereKey($folder->parent_id)->exists()) {
                $folder->parent_id = null;
            }

            // Een vrije naam als er op die plek intussen een map met dezelfde naam staat.
            $base = (string) $folder->name;
            $taken = fn (string $name) => static::query()
                ->where('parent_id', $folder->parent_id)
                ->whereKeyNot($folder->getKey())
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();

            for ($i = 2; $taken((string) $folder->name) && $i < 50; $i++) {
                $folder->name = mb_substr($base, 0, 249) . ' (' . $i . ')';
            }
        });

        // Het pad hoort bij de plek in de boom, en de cache van de
        // meegekomen items draagt nog "in de prullenbak".
        static::restored(function (self $folder) {
            rescue(fn () => app(\Dashed\DashedFiles\Classes\Browse\MediaFolderTree::class)->rebuildPaths());
            rescue(fn () => app(\Dashed\DashedFiles\Classes\MediaLibrary::class)->forgetAllCached());
            \Dashed\DashedFiles\Classes\MediaHelper::forgetServedNotes();
            \Dashed\DashedFiles\Classes\Browse\MediaFolderTree::forgetDuplicatesMemo();
        });

        // folder_id is een harde foreign key zonder cascade: wat er bij een
        // definitieve verwijdering nog naar deze map wijst gaat eerst naar
        // de hoofdmap, en een submap een niveau omhoog.
        static::forceDeleting(function (self $folder) {
            // Een eigen recht, zie MediaItem.
            if ($folder->trashed()) {
                \Dashed\DashedFiles\Classes\MediaLibrary::authorizePurge();
            }

            MediaItem::withoutGlobalScopes()->toBase()->where('folder_id', $folder->getKey())->update(['folder_id' => null]);
            static::withTrashed()->toBase()->where('parent_id', $folder->getKey())->update(['parent_id' => $folder->parent_id]);
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MediaItem::class, 'folder_id');
    }

    /**
     * De mapnamen van de wortel tot en met deze map.
     *
     * @return array<int, string>
     */
    public function pathSegments(): array
    {
        $segments = [];
        $seen = [];
        $folder = $this;

        // De tabel dwingt geen boom af: een map die via een omweg zijn eigen
        // ouder is zou hier anders eindeloos rondlopen.
        while ($folder && ! isset($seen[$folder->getKey()])) {
            $seen[$folder->getKey()] = true;
            array_unshift($segments, (string) $folder->name);
            $folder = $folder->parent;
        }

        return $segments;
    }
}
