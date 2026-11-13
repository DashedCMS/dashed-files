<?php

namespace Dashed\DashedFiles\Models;

use Spatie\MediaLibrary\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Dashed\DashedFiles\Classes\MediaTexts;
use Spatie\MediaLibrary\InteractsWithMedia;
use Illuminate\Database\Eloquent\SoftDeletes;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Dashed\DashedFiles\Models\Builders\MediaItemBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MediaItem extends Model implements HasMedia
{
    use InteractsWithMedia;
    // Het model van het oude pakket kent deleted_at niet: op de motor `legacy` toont het een weggegooid item gewoon. Aanvaard, de prullenbak is alleen via de eigen beheerpagina bereikbaar.
    use SoftDeletes;

    public const MORPH_ALIAS = 'filament_media_library_item';

    public const COLLECTION = 'library';

    // Het vorige bestand van een vervangen item. Blijft staan tot de
    // bewaartermijn `media_replaced`, zodat verstuurde mails en gedeelde
    // links blijven werken.
    public const COLLECTION_REPLACED = 'library-replaced';

    // Een nieuw bestand tijdens het vervangen, tot de wissel rond is.
    public const COLLECTION_INCOMING = 'library-incoming';

    // Het onaangetaste bestand van een bewerkt item (bijsnijden, draaien,
    // spiegelen). Eén per item; blijft staan tot de bewerking wordt
    // teruggedraaid of het item definitief weg is. Niets ruimt het op.
    public const COLLECTION_SOURCE = 'library-source';

    // De automatisch gemaakte voorvertoning van een video (poster) of pdf
    // (miniatuur): een jpeg, hooguit één per item. Nooit het bestand van
    // het item: niets leest hem als hoofdbestand, er komen geen formaten
    // van, en hij gaat weg bij vervangen en bij definitief verwijderen.
    public const COLLECTION_PREVIEW = 'preview';

    public const SCOPE_WITH_FILE = 'with_file';

    // Zo heette de "heeft bestand"-scope op het pakketmodel. MediaItemBuilder
    // vertaalt hem naar SCOPE_WITH_FILE, zodat omgezette klantcode met
    // `withoutGlobalScope('has_media')` hetzelfde blijft doen.
    public const LEGACY_SCOPE_WITH_FILE = 'has_media';

    // Oudere mediarijen kunnen de volle klassenaam dragen in plaats van de
    // alias. Als tekst, zodat dit model niet van het pakket afhangt.
    public const LEGACY_CLASS = 'RalphJSmit\\Filament\\MediaLibrary\\Models\\MediaLibraryItem';

    // De kiezer van het oude pakket, als tekst en zonder import: de
    // gebruiksindex herkent hem zo in een blokschema.
    public const LEGACY_PICKER = 'RalphJSmit\\Filament\\MediaLibrary\\Filament\\Forms\\Components\\MediaPicker';

    protected $table = 'filament_media_library';

    // Bewust geen cast op conversions en conversion_urls: de bestaande code
    // doet zelf json_decode/json_encode op de rauwe string, en een cast zou
    // die waarden dubbel coderen.
    protected $fillable = [
        'uploaded_by_user_id',
        'caption',
        'alt_text',
        'folder_id',
        'conversions',
        'conversion_urls',
        'automatic_alt_tries',
        'checksum',
        'size',
        'title',
        'file_name',
        'mime_type',
        'width',
        'height',
        'source',
        'deleted_by_user_id',
        'trash_batch',
        'trashed_via',
        'focal_x',
        'focal_y',
        'edits',
        'alt_texts',
        'captions',
        'titles',
        'duration',
        'pages',
        'poster_item_id',
        'preview_checked_at',
        'is_private',
        'relocate_to',
    ];

    /**
     * Alt-tekst (`alt`), bijschrift (`caption`) of titel (`title`) in een
     * taal: eerst de gevraagde taal (standaard die van het verzoek), dan de
     * eerste taal van de installatie, anders null. Bewust een eigen terugval:
     * die van spatie/laravel-translatable is `app.fallback_locale`, en dat is
     * niet de eerste taal van de site.
     */
    public function localized(string $field, ?string $locale = null): ?string
    {
        return MediaTexts::resolve($this->translations($field), $locale);
    }

    /**
     * @return array<string, string> taal => tekst, zonder lege waarden
     */
    public function translations(string $field): array
    {
        $name = MediaTexts::field($field);

        if ($name === null) {
            throw new \InvalidArgumentException("Unknown media text field [{$field}].");
        }

        return MediaTexts::of($this, $name);
    }

    /**
     * De talen waarin de alt-tekst ontbreekt.
     *
     * @param  array<int, string>|null  $locales
     * @return array<int, string>
     */
    public function missingAltLocales(?array $locales = null): array
    {
        return MediaTexts::missing($this->translations('alt'), $locales);
    }

    /**
     * Het focuspunt in procenten, of null als het niet gezet is (het midden).
     *
     * @return array{x: int, y: int}|null
     */
    public function focalPoint(): ?array
    {
        $x = $this->getAttribute('focal_x');
        $y = $this->getAttribute('focal_y');

        return is_numeric($x) && is_numeric($y) ? ['x' => (int) $x, 'y' => (int) $y] : null;
    }

    /**
     * Het recept van de bewerking, of null als het bestand onbewerkt is.
     *
     * @return array<string, mixed>|null
     */
    public function editRecipe(): ?array
    {
        $edits = $this->getAttribute('edits');
        $edits = is_string($edits) ? json_decode($edits, true) : $edits;

        return is_array($edits) && $edits !== [] ? $edits : null;
    }

    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new \Dashed\DashedFiles\Models\Scopes\MediaTrashScope());
    }

    protected static function booted(): void
    {
        // Een item zonder mediarij is een mislukte of half afgebroken upload
        // en hoort nergens op te duiken, net als bij het pakketmodel.
        // Alleen de collectie van de bibliotheek: het bewaarde bestand van
        // een vervangen item (library-replaced) telt niet als "heeft bestand".
        static::addGlobalScope(self::SCOPE_WITH_FILE, fn (Builder $query) => $query->whereHas('media', fn (Builder $media) => $media->where('collection_name', self::COLLECTION)));

        // Terugzetten buiten de beheerpagina om (de prullenbak van dashed-core,
        // "Ongedaan maken"): een item waarvan de map weg is of zelf in de
        // prullenbak ligt komt in de hoofdmap.
        static::restoring(function (self $item) {
            if ($item->folder_id !== null && ! MediaFolder::query()->whereKey($item->folder_id)->exists()) {
                $item->folder_id = null;
            }

            $item->trash_batch = null;
            $item->deleted_by_user_id = null;

            if (BrowseSchema::hasTrashOrigin()) {
                $item->trashed_via = null;
            }

            if (BrowseSchema::hasServed()) {
                $item->trashed_served_at = null;
            }
        });

        // Wie het weggooide, centraal voor elke `->delete()` op het model:
        // ook klantcode en taken die het oude pakket gewend zijn. Een aparte
        // update na het weggooien, want de update van SoftDeletes neemt alleen
        // deleted_at mee. De mediaschermen zetten de herkomst vast via
        // MediaTrashOrigin::as().
        static::softDeleted(function (self $item) {
            if (! BrowseSchema::hasTrashOrigin()) {
                return;
            }

            $values = ['trashed_via' => \Dashed\DashedFiles\Classes\Browse\MediaTrashOrigin::current()];

            if ($item->getAttribute('deleted_by_user_id') === null && ($userId = \Dashed\DashedFiles\Classes\Browse\MediaTrashOrigin::userId()) !== null) {
                $values['deleted_by_user_id'] = $userId;
            }

            static::withoutGlobalScopes()->toBase()->where($item->getKeyName(), $item->getKey())->update($values);
            $item->forceFill($values)->syncOriginalAttributes(array_keys($values));
        });

        // De payload in de cache draagt of het item in de prullenbak ligt.
        static::restored(fn (self $item) => rescue(fn () => app(\Dashed\DashedFiles\Classes\Browse\MediaBulk::class)->afterRestoring([(int) $item->getKey()])));

        // Definitief verwijderen uit de prullenbak is een eigen recht
        // (purge_media), ook via de algemene prullenbakpagina van dashed-core.
        // Een mislukte upload opruimen (het item ligt dan niet in de
        // prullenbak) en alles zonder ingelogde gebruiker valt erbuiten.
        static::forceDeleting(function (self $item) {
            if ($item->trashed()) {
                \Dashed\DashedFiles\Classes\MediaLibrary::authorizePurge();
            }
        });

        // Wat geen foreign key heeft ruimt alleen een definitieve
        // verwijdering op; in de prullenbak blijven tags, favorieten en de
        // gebruiksindex staan zodat terugzetten alles terugbrengt.
        static::forceDeleted(function (self $item) {
            rescue(fn () => app(\Dashed\DashedFiles\Classes\Browse\MediaTagging::class)->forgetItems([$item->getKey()]));
            rescue(fn () => app(\Dashed\DashedFiles\Classes\Usage\MediaUsageIndexer::class)->forgetTarget((int) $item->getKey()));
            rescue(fn () => app(\Dashed\DashedFiles\Classes\MediaLibrary::class)->invalidate($item, 'deleted'));
            // Een video die deze afbeelding als poster had valt terug op zijn eigen voorvertoning.
            rescue(fn () => app(\Dashed\DashedFiles\Classes\Preview\MediaPreviews::class)->invalidateUsersOfPoster((int) $item->getKey()));
        });
    }

    // De alias en niet de eigen klassenaam: zo vinden media() en
    // whereHas('media') dezelfde rijen als het pakketmodel, en is een rij
    // die wij aanmaken ook voor het pakketmodel zichtbaar.
    public function getMorphClass(): string
    {
        return self::MORPH_ALIAS;
    }

    public static function collectionName(): string
    {
        return self::COLLECTION;
    }

    public function file(): ?Media
    {
        return $this->getFirstMedia(self::COLLECTION);
    }

    /**
     * @deprecated Gebruik file(). Bestaat voor code die van het oude pakket
     *             komt; anders dan daar geeft een item zonder bestand null
     *             in plaats van een TypeError.
     */
    public function getItem(): ?Media
    {
        return $this->file();
    }

    /**
     * @deprecated Gebruik collectionName(). Bestaat voor code die van het
     *             oude pakket komt, zoals
     *             `getFirstMediaUrl($item->getMediaLibraryCollectionName())`.
     */
    public function getMediaLibraryCollectionName(): string
    {
        return self::COLLECTION;
    }

    /** Zie MediaItemBuilder: vertaalt de oude scopenaam `has_media`. */
    public function newEloquentBuilder($query): MediaItemBuilder
    {
        return new MediaItemBuilder($query);
    }

    /** De automatisch gemaakte voorvertoning (poster of miniatuur), of null. */
    public function preview(): ?Media
    {
        return $this->getFirstMedia(self::COLLECTION_PREVIEW);
    }

    /** De afbeelding die een beheerder als poster koos, ook als die in de prullenbak ligt. */
    public function posterItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'poster_item_id')->withTrashed();
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MediaTag::class, BrowseSchema::ITEM_TAG, 'media_item_id', 'media_tag_id');
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(config('auth.providers.users.model', \Dashed\DashedCore\Models\User::class), BrowseSchema::FAVORITES, 'media_item_id', 'user_id')
            ->withPivot('created_at');
    }

    // De titel is wat een beheerder zelf invult; zonder titel de bestandsnaam.
    public function getDisplayNameAttribute(): string
    {
        return (string) (filled($this->title) ? $this->title : ($this->file_name ?? ''));
    }

    // De prullenbak van dashed-core zoekt een titel onder `name`.
    public function getNameAttribute(): string
    {
        return $this->display_name;
    }

    public static function fromMedia(?Media $media): ?self
    {
        if (! $media || ! in_array((string) $media->model_type, [self::MORPH_ALIAS, self::LEGACY_CLASS], true)) {
            return null;
        }

        // Niet via $media->model: op de oude motor levert dat de pakketklasse
        // op waar de alias naar wijst, en op de eigen motor past het de
        // scopes toe. En zonder de scope, want die telt alleen rijen
        // onder de alias terwijl deze mediarij zelf al het bestand is.
        // Ook uit de prullenbak: de site blijft zo'n item tonen tot het echt weg is.
        return static::withoutGlobalScope(self::SCOPE_WITH_FILE)->withTrashed()->find($media->model_id);
    }

    // De eerste taal: de tekstkolom is daar de spiegel van.
    public function scopeMissingAltText(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('alt_text')->orWhere('alt_text', ''));
    }

    /**
     * Leeg in minstens één van deze talen (standaard alle talen van de
     * installatie). Voor de spiegeltaal telt de tekstkolom, voor de andere
     * de vertalingen.
     *
     * De vertalingen zijn een tekstkolom met JSON erin. Bewust een LIKE op
     * de sleutel en geen JSON-pad: op MySQL laat één rij met ongeldige JSON
     * of een lege tekst een query met een JSON-functie in zijn geheel falen.
     * setTexts() schrijft compacte JSON zonder lege waarden, dus `"en":"`
     * staat er precies als de taal gevuld is (in een waarde is een
     * aanhalingsteken ge-escapet en kan dat patroon niet voorkomen). Een
     * rij met ongeldige of anders opgemaakte JSON telt als "mist", tot
     * `dashed:media-texts --sync` hem heeft rechtgezet.
     *
     * @param  array<int, string>|null  $locales
     */
    public function scopeMissingAltTextInAnyLocale(Builder $query, ?array $locales = null): void
    {
        $locales ??= MediaTexts::locales();
        $first = MediaTexts::firstLocale();
        $others = MediaTexts::hasColumns() ? array_values(array_filter($locales, fn (string $locale) => $locale !== $first && MediaTexts::looksLikeLocale($locale))) : [];
        $table = $this->getTable();

        $query->where(function (Builder $query) use ($locales, $first, $others, $table) {
            if (in_array($first, $locales, true) || $others === []) {
                $query->whereNull($table . '.alt_text')->orWhere($table . '.alt_text', '');
            }

            foreach ($others as $locale) {
                $query->orWhereNull($table . '.alt_texts')->orWhere($table . '.alt_texts', 'not like', '%"' . $locale . '":"%');
            }
        });
    }

    public function scopeOfMimeTypes(Builder $query, array $mimes): void
    {
        // Alleen het bestand van het item: de voorvertoning van een video is een jpeg.
        $query->whereHas('media', fn (Builder $query) => $query->where('collection_name', self::COLLECTION)->whereIn('mime_type', $mimes));
    }

    /**
     * Op de eigen motor wijst de morph-alias naar dit model
     * (DashedFilesServiceProvider::mapMorphAlias) en registreert het zelf de
     * formaten. Op de oude motor wijst de alias naar de pakketklasse en roept
     * Spatie deze methode niet aan.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        \Dashed\DashedFiles\Classes\MediaConversions::registerForLibrary($this, $media);
    }
}
