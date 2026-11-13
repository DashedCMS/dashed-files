<?php

namespace Dashed\DashedFiles\Classes;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Zet de eigen formaten van een media-item op het Spatie-model.
 */
class MediaConversions
{
    // De miniatuur van de bibliotheek, zoals het oude pakket hem maakte:
    // 600 bij 600, bijgesneden rond het midden, ook als de bron kleiner is.
    public const THUMB = 'thumb';

    public const THUMB_SIZE = 600;

    /**
     * Alles wat een item van de bibliotheek op de eigen motor krijgt: de
     * miniatuur, `medium` en wat een thema ooit vroeg (de kolom
     * `conversions`). De namen en dus de bestandsnamen zijn die van het oude
     * pakket (`<naam>-thumb.webp`, `<naam>-medium.webp`): bestaande bestanden
     * worden hergebruikt en `--only-missing` slaat ze over.
     *
     * `responsive` van het oude pakket (de bron op ware grootte als webp plus
     * een bestand in `responsive/`) komt er bewust niet bij: niets in de
     * pakketten of de app leest dat formaat of de responsive images van
     * Spatie, en het is een volledige kopie van elke upload. Bestaande
     * bestanden blijven staan.
     */
    public static function registerForLibrary(HasMedia $item, ?Media $media): void
    {
        // Een video, pdf, svg of gif wordt als origineel uitgeleverd
        // (MediaHelper, MediaItemPayload): daar valt niets van te maken.
        if ($media && ! self::isConvertible((string) $media->mime_type)) {
            return;
        }

        // De voorvertoning van een video of pdf is zelf al het eindproduct:
        // daar komen geen formaten van.
        if ($media && $media->collection_name === \Dashed\DashedFiles\Models\MediaItem::COLLECTION_PREVIEW) {
            return;
        }

        [$json, $focalX, $focalY] = self::storedRow($item, $media);

        // Een nieuw bestand dat nog op de wissel wacht (vervangen, bewerken)
        // krijgt nooit het focuspunt van het oude beeld: de wissel zet het terug.
        if ($media && $media->collection_name === \Dashed\DashedFiles\Models\MediaItem::COLLECTION_INCOMING) {
            $focalX = $focalY = null;
        }

        // Met de beheerminiatuur: die wordt bij de inname gemaakt, naast `thumb`.
        $definitions = self::libraryDefinitions($json, $focalX, $focalY, withAdminThumb: true);

        foreach ($definitions as $definition) {
            self::add($item, $definition);
        }

        // AVIF is een eigen formaat naast het webp-formaat. Het wordt alleen
        // geregistreerd als het er al is, of terwijl de pomp het maakt: zo
        // komt het nooit mee bij een upload of op het pad van een bezoeker.
        if (! $media || ! MediaLibrary::avifEnabled()) {
            return;
        }

        $generated = (array) $media->generated_conversions;

        foreach ($definitions as $definition) {
            $avif = $definition->asAvif();

            if (self::$generatingAvif ? MediaCapabilities::avif() : ! empty($generated[$avif->name])) {
                self::add($item, $avif);
            }
        }
    }

    private static bool $generatingAvif = false;

    /**
     * Voert de callback uit terwijl de AVIF-varianten van elk formaat
     * geregistreerd zijn. Alleen de pomp roept dit aan.
     */
    public static function whileGeneratingAvif(callable $callback): mixed
    {
        $before = self::$generatingAvif;
        self::$generatingAvif = true;

        try {
            return $callback();
        } finally {
            self::$generatingAvif = $before;
        }
    }

    /**
     * Elk formaat dat een item van de bibliotheek op de eigen motor heeft,
     * op naam: de miniatuur, wat de kolom `conversions` vraagt en `medium`.
     * Een formaat dat bijsnijdt draagt bij een gezet focuspunt het
     * achtervoegsel daarvan in zijn naam. De registratie bij Spatie en de
     * pomp lezen allebei deze lijst.
     *
     * De beheerminiatuur (`admin-thumb`) staat er alleen bij met
     * `$withAdminThumb`: de registratie (inname) en het opruimen willen hem,
     * de rest (wat een item "hoort te hebben") niet. Zo telt hij niet mee
     * als "ontbrekend" voor elk bestaand item bij een uitrol.
     *
     * @return array<string, ConversionDefinition>
     */
    public static function libraryDefinitions(?string $conversionsJson, ?int $focalX = null, ?int $focalY = null, bool $withAdminThumb = false): array
    {
        $definitions = [];
        $first = $withAdminThumb ? [ConversionDefinition::thumb(), ConversionDefinition::adminThumb()] : [ConversionDefinition::thumb()];

        foreach (array_merge($first, array_values(self::definitionsFor($conversionsJson))) as $definition) {
            $definition = $definition->withFocal($focalX, $focalY);
            $definitions[$definition->name] ??= $definition;
        }

        return $definitions;
    }

    /** De naam van de miniatuur voor dit focuspunt: `thumb` of `thumb-f3070`. */
    public static function thumbName(?int $focalX, ?int $focalY): string
    {
        return ConversionDefinition::thumb()->withFocal($focalX, $focalY)->name;
    }

    /** De naam van de beheerminiatuur voor dit focuspunt: `admin-thumb` of `admin-thumb-f3070`. */
    public static function adminThumbName(?int $focalX, ?int $focalY): string
    {
        return ConversionDefinition::adminThumb()->withFocal($focalX, $focalY)->name;
    }

    public static function isConvertible(string $mime): bool
    {
        // Alleen wat de actieve beelddriver echt kan inlezen. Een heic, tiff,
        // ico of psd die hier doorheen kwam leverde alleen mislukte taken op.
        return MediaCapabilities::canRead($mime);
    }

    public static function register(HasMedia $item, ?Media $media): void
    {
        foreach (self::definitionsFor(self::storedConversions($item, $media)) as $definition) {
            self::add($item, $definition);
        }
    }

    private static function add(HasMedia $item, ConversionDefinition $definition): void
    {
        $conversion = $item->addMediaConversion($definition->name)->format($definition->format);

        match ($definition->mode) {
            ConversionDefinition::MODE_WIDTH => $conversion->width($definition->width),
            ConversionDefinition::MODE_HEIGHT => $conversion->height($definition->height),
            // Met een focuspunt eerst de uitsnede rond dat punt. Die komt bij
            // Spatie door afronding soms een paar pixels naast de gevraagde
            // maat uit; de gewone uitsnede erna maakt de maat exact, zonder
            // te vervormen. Zonder focuspunt is dit precies wat het altijd was.
            ConversionDefinition::MODE_CROP => $definition->focal
                ? $conversion->focalCropAndResize($definition->width, $definition->height, $definition->focal['x'], $definition->focal['y'])->fit(Fit::Crop, $definition->width, $definition->height)
                : $conversion->fit(Fit::Crop, $definition->width, $definition->height),
            ConversionDefinition::MODE_CONTAIN => $conversion->fit(Fit::Contain, $definition->width, $definition->height),
        };
    }

    /**
     * De definities voor de inhoud van de kolom `conversions`, elke naam één
     * keer. `medium` hoort er altijd bij, ook bij een lege kolom: het is de
     * terugval zolang een gevraagd formaat nog niet gegenereerd is.
     *
     * @return array<string, ConversionDefinition>
     */
    public static function definitionsFor(?string $conversionsJson): array
    {
        $stored = json_decode($conversionsJson ?: '[]', true);
        $definitions = [];

        foreach (is_array($stored) ? $stored : [] as $key) {
            if (! is_string($key) && ! is_array($key)) {
                continue;
            }

            $definition = ConversionDefinition::fromLegacy($key);

            if ($definition) {
                $definitions[$definition->name] ??= $definition;
            }
        }

        $definitions['medium'] ??= ConversionDefinition::medium();

        return $definitions;
    }

    /**
     * De kolom `conversions` en het focuspunt van het item achter een mediarij.
     *
     * @return array{0: ?string, 1: ?int, 2: ?int}
     */
    private static function storedRow(HasMedia $item, ?Media $media): array
    {
        $number = fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;

        if ($media && $item instanceof Model && $item->exists && $item->getKey() == $media->model_id) {
            return [$item->conversions, $number($item->getAttribute('focal_x')), $number($item->getAttribute('focal_y'))];
        }

        if (! $media || $media->model_id === null) {
            return [null, null, null];
        }

        // Eén query voor beide: het item dat de formaten levert draagt ook het focuspunt.
        $loaded = false;
        $found = null;

        $conversions = MediaPickerFileCache::rememberConversions($media->model_id, function () use ($media, &$loaded, &$found) {
            $loaded = true;
            $found = app(MediaLibrary::class)->find($media->model_id, includeWithoutFile: true, withTrashed: true);

            return $found?->conversions;
        });

        $focal = MediaPickerFileCache::rememberFocal($media->model_id, function () use ($media, &$loaded, &$found) {
            if ($loaded) {
                return [$found?->getAttribute('focal_x'), $found?->getAttribute('focal_y')];
            }

            if (! Browse\BrowseSchema::hasFocal()) {
                return [null, null];
            }

            $row = \Dashed\DashedFiles\Models\MediaItem::withoutGlobalScopes()->toBase()->where('id', $media->model_id)->first(['focal_x', 'focal_y']);

            return [$row?->focal_x, $row?->focal_y];
        });

        return [$conversions, $focal[0], $focal[1]];
    }

    private static function storedConversions(HasMedia $item, ?Media $media): ?string
    {
        // Spatie roept de registratie aan op een leeg model, dus de formaten
        // moeten van de rij komen. Dat gebeurt bij elke afbeeldings-URL
        // opnieuw; per verzoek één keer per item is genoeg.
        if ($media && $item instanceof Model && $item->exists && $item->getKey() == $media->model_id) {
            return $item->conversions;
        }

        if ($media && $media->model_id !== null) {
            return MediaPickerFileCache::rememberConversions(
                $media->model_id,
                fn () => app(MediaLibrary::class)->find($media->model_id, includeWithoutFile: true, withTrashed: true)?->conversions,
            );
        }

        return null;
    }
}
