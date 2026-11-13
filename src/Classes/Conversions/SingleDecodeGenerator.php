<?php

namespace Dashed\DashedFiles\Classes\Conversions;

use GdImage;
use Imagick;
use Throwable;
use Spatie\Image\Size;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\Constraint;
use Spatie\Image\Drivers\Gd\GdDriver;
use Spatie\Image\Enums\AlignPosition;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\Events\ConversionWillStartEvent;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

/**
 * Maakt de formaten van één bibliotheekitem uit één decodering van het
 * origineel, in plaats van het origineel per formaat opnieuw in te lezen
 * zoals Spatie doet.
 *
 * Naam, map, bestandsnaam, administratie (`generated_conversions`) en events
 * zijn die van de Spatie-route: de bestanden worden door dezelfde
 * `Filesystem` weggeschreven en het formaat wordt met dezelfde aanroepen als
 * gegenereerd gemarkeerd. Kan deze klasse een definitie niet aan (een andere
 * bewerking dan hieronder, een ander uitvoerformaat, responsive images, een
 * bron die geen jpeg, png of webp is), dan geeft `generate()` false en
 * valt de pomp voor dat formaat terug op Spatie.
 *
 * Gd: het origineel wordt één keer gedecodeerd (met EXIF-draaiing) en elk
 * formaat draait de manipulaties van Spatie op een kopie van die driver, dus
 * de geometrie is die van Spatie zelf. Van groot naar klein wordt een
 * verkleind resultaat bewaard als tussenstap voor kleinere formaten, mits het
 * minstens twee keer zo groot is als nodig.
 *
 * Imagick: eigen route met `jpeg:size` (de JPEG-decoder levert direct een
 * verkleinde versie), `thumbnailImage`, `stripImage()` (geen EXIF of GPS in
 * het formaat) en een expliciete kwaliteit. De maten worden met de
 * Size-klasse van Spatie uitgerekend, zodat ze gelijk zijn aan die van de
 * Spatie-route.
 */
final class SingleDecodeGenerator
{
    /** Bewerkingen die deze klasse kent; alles daarbuiten gaat naar Spatie. */
    private const KNOWN = ['optimize', 'format', 'quality', 'width', 'height', 'fit', 'focalCropAndResize'];

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Een tussenstap moet minstens zoveel keer zo groot zijn als het doel. */
    private const MARGIN = 2;

    private ?GdDriver $gd = null;

    /** @var array<int, GdDriver> verkleinde resultaten, groot naar klein */
    private array $gdSteps = [];

    private ?Imagick $imagick = null;

    private int $originalWidth = 0;

    private int $originalHeight = 0;

    /** @param  array<int, array<string, array<int|string, mixed>>>  $sets de manipulaties van de formaten die komen, voor de hint van de JPEG-decoder */
    public function __construct(
        private readonly string $original,
        private readonly string $driver,
        private readonly array $sets = [],
    ) {
    }

    public static function enabled(): bool
    {
        return (bool) config('dashed-files.conversions.single_decode', false);
    }

    public static function quality(): int
    {
        return max(1, min(100, (int) (config('dashed-files.conversions.quality') ?: 80)));
    }

    /**
     * De namen van deze formaten van groot naar klein (op doelpixels), zodat
     * een tussenstap bruikbaar is voor wat daarna komt. Alles wat geen
     * afmetingen heeft houdt zijn plek achteraan.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    public static function order(Media $media, array $names): array
    {
        $area = [];

        foreach (ConversionCollection::createForMedia($media) as $conversion) {
            $target = self::target($conversion->getManipulations()->toArray(), 1.0);
            $area[$conversion->getName()] = $target ? $target[0] * $target[1] : 0;
        }

        $positions = array_flip($names);
        usort($names, fn (string $a, string $b) => ($area[$b] ?? 0) <=> ($area[$a] ?? 0) ?: $positions[$a] <=> $positions[$b]);

        return $names;
    }

    /**
     * De doelmaat van een set manipulaties bij deze beeldverhouding, of null
     * als er geen is te bepalen.
     *
     * @param  array<string, array<int|string, mixed>>  $manipulations
     * @return array{0: int, 1: int}|null
     */
    private static function target(array $manipulations, float $aspect): ?array
    {
        if (isset($manipulations['width'][0])) {
            $width = (int) $manipulations['width'][0];

            return [$width, max(1, (int) round($width / $aspect))];
        }

        if (isset($manipulations['height'][0])) {
            $height = (int) $manipulations['height'][0];

            return [max(1, (int) round($height * $aspect)), $height];
        }

        if (isset($manipulations['fit'][1], $manipulations['fit'][2])) {
            return [(int) $manipulations['fit'][1], (int) $manipulations['fit'][2]];
        }

        return null;
    }

    /**
     * Of deze klasse dit formaat zelf maakt, zonder iets in te lezen.
     */
    public function supports(Media $media, Conversion $conversion): bool
    {
        if (! in_array((string) $media->mime_type, self::MIMES, true)) {
            return false;
        }

        if ($conversion->shouldGenerateResponsiveImages() || $conversion->shouldKeepOriginalImageFormat()) {
            return false;
        }

        $manipulations = $conversion->getManipulations()->toArray();

        if ($manipulations === [] || array_diff(array_keys($manipulations), self::KNOWN) !== []) {
            return false;
        }

        if (($manipulations['format'][0] ?? null) !== 'webp') {
            return false;
        }

        $geometry = array_intersect(array_keys($manipulations), ['width', 'height', 'fit', 'focalCropAndResize']);

        // Eén bewerking, of de uitsnede rond een focuspunt met de gewone uitsnede erachter.
        if (count($geometry) === 0 || count($geometry) > 2) {
            return false;
        }

        if (count($geometry) === 2) {
            if (! isset($manipulations['focalCropAndResize'], $manipulations['fit'])) {
                return false;
            }

            if (($manipulations['fit'][0] ?? null) !== Fit::Crop
                || ($manipulations['fit'][1] ?? null) !== ($manipulations['focalCropAndResize'][0] ?? null)
                || ($manipulations['fit'][2] ?? null) !== ($manipulations['focalCropAndResize'][1] ?? null)) {
                return false;
            }
        } elseif (isset($manipulations['fit']) && ! in_array($manipulations['fit'][0] ?? null, [Fit::Crop, Fit::Contain], true)) {
            return false;
        } elseif (isset($manipulations['focalCropAndResize'])) {
            return false;
        }

        return true;
    }

    /**
     * Maakt het formaat en boekt het zoals Spatie dat doet. False als dit
     * formaat voor Spatie is (of hier mislukte): er is dan niets geschreven.
     */
    public function generate(Media $media, Conversion $conversion): bool
    {
        if (! $this->supports($media, $conversion)) {
            return false;
        }

        $directory = pathinfo($this->original, PATHINFO_DIRNAME);
        $file = $directory . '/' . $conversion->getConversionFile($media);

        try {
            event(new ConversionWillStartEvent($media, $conversion, $this->original));

            $manipulations = $conversion->getManipulations()->toArray();

            $this->driver === 'imagick'
                ? $this->makeWithImagick($manipulations, $file)
                : $this->makeWithGd($conversion, $manipulations, $file);

            if (! is_file($file) || filesize($file) === 0) {
                @unlink($file);

                return false;
            }
        } catch (Throwable) {
            @unlink($file);

            return false;
        }

        app(Filesystem::class)->copyToMediaLibrary($file, $media, 'conversions');

        @unlink($file);

        $media->markAsConversionGenerated($conversion->getName());

        event(new ConversionHasBeenCompletedEvent($media, $conversion));

        return true;
    }

    /** Geeft de decodering vrij. */
    public function release(): void
    {
        $this->gd = null;
        $this->gdSteps = [];
        $this->imagick?->clear();
        $this->imagick = null;
    }

    // Gd

    private function makeWithGd(Conversion $conversion, array $manipulations, string $file): void
    {
        $this->gd ??= (new GdDriver())->loadFile($this->original);

        $aspect = $this->gd->getWidth() / max(1, $this->gd->getHeight());
        $target = self::target($manipulations, $aspect);

        // De kleinste tussenstap die nog twee keer zo groot is als nodig, anders het origineel.
        $source = $this->gd;

        if ($target) {
            $needed = max($target[0], (int) ceil($target[1] * $aspect)) * self::MARGIN;

            foreach ($this->gdSteps as $step) {
                if ($step->getWidth() >= $needed && $step->getHeight() >= $needed / $aspect) {
                    $source = $step;
                }
            }
        }

        // Een kopie deelt het GdImage; de bewerkingen van Spatie maken een nieuw
        // beeld in plaats van het gedeelde aan te passen.
        $driver = clone $source;
        $driver->format('jpg');

        $conversion->getManipulations()->apply($driver);
        $driver->quality(self::quality());
        $driver->save($file);

        $keepsAspect = isset($manipulations['width']) || isset($manipulations['height']);

        if ($keepsAspect) {
            $this->gdSteps[] = $driver;
        }
    }

    // Imagick

    /**
     * @param  array<string, array<int|string, mixed>>  $manipulations
     */
    private function makeWithImagick(array $manipulations, string $file): void
    {
        $base = $this->imagickBase();

        $width = $this->originalWidth;
        $height = $this->originalHeight;
        $size = new Size($width, $height);

        $crop = null;

        if (isset($manipulations['focalCropAndResize'])) {
            [$targetWidth, $targetHeight, $x, $y] = array_pad($manipulations['focalCropAndResize'], 4, null);
            $crop = $this->focalRectangle($width, $height, (int) $targetWidth, (int) $targetHeight, $x, $y);
            $final = new Size((int) $targetWidth, (int) $targetHeight);
            $resized = $final;
        } elseif (isset($manipulations['width'])) {
            $resized = $this->sizeFromWidth($size, (int) $manipulations['width'][0]);
            $final = $resized;
        } elseif (isset($manipulations['height'])) {
            $resized = $this->sizeFromHeight($size, (int) $manipulations['height'][0]);
            $final = $resized;
        } elseif (($manipulations['fit'][0] ?? null) === Fit::Contain) {
            $resized = Fit::Contain->calculateSize($width, $height, (int) $manipulations['fit'][1], (int) $manipulations['fit'][2]);
            $final = $resized;
        } else {
            $desiredWidth = (int) $manipulations['fit'][1];
            $desiredHeight = (int) $manipulations['fit'][2];
            $resized = $this->coverSize($size, $desiredWidth, $desiredHeight);
            $final = new Size($desiredWidth, $desiredHeight);
        }

        $image = clone $base;
        $factor = $image->getImageWidth() / max(1, $width);

        if ($crop) {
            $image->cropImage(
                max(1, (int) round($crop[0] * $factor)),
                max(1, (int) round($crop[1] * $factor)),
                (int) round($crop[2] * $factor),
                (int) round($crop[3] * $factor),
            );
            $image->setImagePage(0, 0, 0, 0);
        }

        $image->thumbnailImage($resized->width, $resized->height);

        if (! $crop && ($resized->width !== $final->width || $resized->height !== $final->height)) {
            // Bijsnijden rond het midden, zoals de canvas-stap van Spatie.
            $canvas = (new Size($final->width, $final->height))->align(AlignPosition::Center);
            $placed = (new Size($resized->width, $resized->height))->align(AlignPosition::Center);
            $position = $placed->relativePosition($canvas);

            $image->cropImage($final->width, $final->height, $position->x, $position->y);
            $image->setImagePage(0, 0, 0, 0);
        }

        if ($image->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }

        // Geen EXIF, GPS of andere profielen in een formaat.
        $image->stripImage();
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality(self::quality());
        $image->writeImage($file);
        $image->clear();

        // Zelfde nabewerking als de Spatie-route: de optimizerketen uit de manipulaties.
        $chain = $manipulations['optimize'][0] ?? null;

        if (is_object($chain) && method_exists($chain, 'optimize')) {
            $chain->optimize($file);
        }
    }

    private function imagickBase(): Imagick
    {
        if ($this->imagick) {
            return $this->imagick;
        }

        $probe = new Imagick();
        $probe->pingImage($this->original);
        $isJpeg = strtoupper($probe->getImageFormat()) === 'JPEG';
        $width = $probe->getImageWidth();
        $height = $probe->getImageHeight();
        $orientation = $probe->getImageOrientation();
        $probe->clear();

        // Een gedraaide opname (EXIF 5 tot en met 8) heeft de maten omgewisseld.
        $swapped = in_array($orientation, [Imagick::ORIENTATION_LEFTTOP, Imagick::ORIENTATION_RIGHTTOP, Imagick::ORIENTATION_RIGHTBOTTOM, Imagick::ORIENTATION_LEFTBOTTOM], true);

        $this->originalWidth = $swapped ? $height : $width;
        $this->originalHeight = $swapped ? $width : $height;

        $image = new Imagick();

        // De JPEG-decoder kan direct op 1/2, 1/4 of 1/8 inlezen: scheelt tijd en geheugen.
        // De hint is in de maten van het bestand, dus vóór het draaien.
        $largest = $isJpeg ? $this->largestNeeded($this->originalWidth / max(1, $this->originalHeight)) : null;

        if ($largest) {
            $hintWidth = ($swapped ? $largest[1] : $largest[0]) * self::MARGIN;
            $hintHeight = ($swapped ? $largest[0] : $largest[1]) * self::MARGIN;

            $image->setOption('jpeg:size', $hintWidth . 'x' . $hintHeight);
        }

        $image->readImage($this->original);
        $image->autoOrient();

        return $this->imagick = $image;
    }

    /**
     * De grootste breedte en hoogte die één van de komende formaten van de
     * bron nodig heeft (bij bijsnijden: genoeg om de maat te dekken).
     *
     * @return array{0: int, 1: int}|null
     */
    private function largestNeeded(float $aspect): ?array
    {
        $width = 0;

        foreach ($this->sets as $set) {
            $target = self::target($set, $aspect);

            if (! $target) {
                return null;
            }

            $width = max($width, $target[0], (int) ceil($target[1] * $aspect));
        }

        return $width > 0 ? [$width, (int) ceil($width / $aspect)] : null;
    }

    private function sizeFromWidth(Size $size, int $width): Size
    {
        return $size->resize($width, (int) round($width / $size->aspectRatio()), [Constraint::PreserveAspectRatio]);
    }

    private function sizeFromHeight(Size $size, int $height): Size
    {
        return $size->resize((int) round($height * $size->aspectRatio()), $height, [Constraint::PreserveAspectRatio]);
    }

    /**
     * De maat waarop Spatie `fit(Crop)` verkleint voor het bijsnijden.
     */
    private function coverSize(Size $original, int $desiredWidth, int $desiredHeight): Size
    {
        $current = $original;

        if ($original->width < $desiredWidth || $original->height < $desiredHeight) {
            $current = $desiredWidth < $desiredHeight
                ? $this->sizeFromHeight($current, $desiredHeight)
                : $this->sizeFromWidth($current, $desiredWidth);
        }

        $current = ($original->width / $original->height) > ($desiredWidth / $desiredHeight)
            ? $this->sizeFromHeight($current, $desiredHeight)
            : $this->sizeFromWidth($current, $desiredWidth);

        // Door afronding kan één zijde een pixel te kort uitkomen.
        return new Size(max($current->width, $desiredWidth), max($current->height, $desiredHeight));
    }

    /**
     * De uitsnede rond een focuspunt in de maten van het origineel (breedte,
     * hoogte, x, y), zoals Spatie hem berekent.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function focalRectangle(int $width, int $height, int $desiredWidth, int $desiredHeight, mixed $centerX, mixed $centerY): array
    {
        $centerX = $centerX === null ? 50.0 : (float) $centerX;
        $centerY = $centerY === null ? 50.0 : (float) $centerY;

        $targetRatio = $desiredWidth / $desiredHeight;

        if (($width / $height) > $targetRatio) {
            $cropHeight = $height;
            $cropWidth = (int) round($cropHeight * $targetRatio);
        } else {
            $cropWidth = $width;
            $cropHeight = (int) round($cropWidth / $targetRatio);
        }

        $x = max(0, min((int) ($width * ($centerX / 100) - $cropWidth / 2), $width - $cropWidth));
        $y = max(0, min((int) ($height * ($centerY / 100) - $cropHeight / 2), $height - $cropHeight));

        return [$cropWidth, $cropHeight, $x, $y];
    }
}
