<?php

namespace Dashed\DashedFiles\Classes;

use Throwable;
use Spatie\Image\Image;
use Spatie\Image\Enums\Orientation;
use Spatie\Image\Enums\FlipDirection;
use Dashed\DashedFiles\Classes\Ingest\IncomingMedia;
use Dashed\DashedFiles\Classes\Ingest\MediaRejectedException;

/**
 * Het recept van een bewerkte afbeelding: draaien, spiegelen en bijsnijden,
 * in die volgorde. De uitsnede staat in procenten van het beeld zoals het
 * er na het draaien en spiegelen uitziet (zo ziet de beheerder het ook in
 * de dialoog); zonder draaien of spiegelen zijn dat procenten van de bron.
 */
final class MediaEdits
{
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const ROTATIONS = [0, 90, 180, 270];

    public const FLIPS = ['h', 'v', 'hv'];

    // Kleiner dan dit in een van beide richtingen is geen bruikbare uitsnede.
    public const MIN_PIXELS = 8;

    /**
     * @param  array<string, mixed>  $recipe
     * @return array{rotate: int, flip: ?string, crop: ?array{x: float, y: float, w: float, h: float}}
     *
     * @throws MediaRejectedException
     */
    public static function validate(array $recipe): array
    {
        $rotate = $recipe['rotate'] ?? 0;

        if (! is_numeric($rotate) || ! in_array((int) $rotate, self::ROTATIONS, true) || (float) $rotate !== (float) (int) $rotate) {
            throw new MediaRejectedException(__('Draaien kan alleen in stappen van 90 graden.'));
        }

        $flip = $recipe['flip'] ?? null;
        $flip = $flip === '' || $flip === false ? null : $flip;

        if ($flip === 'vh') {
            $flip = 'hv';
        }

        if ($flip !== null && (! is_string($flip) || ! in_array($flip, self::FLIPS, true))) {
            throw new MediaRejectedException(__('Spiegelen kan alleen horizontaal of verticaal.'));
        }

        $crop = $recipe['crop'] ?? null;

        if ($crop !== null) {
            if (! is_array($crop)) {
                throw new MediaRejectedException(__('De uitsnede is ongeldig.'));
            }

            $values = [];

            foreach (['x', 'y', 'w', 'h'] as $key) {
                $value = $crop[$key] ?? null;

                if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                    throw new MediaRejectedException(__('De uitsnede is ongeldig.'));
                }

                $value = (float) $value;

                if (! is_finite($value) || $value < 0 || $value > 100) {
                    throw new MediaRejectedException(__('De uitsnede valt buiten het beeld.'));
                }

                $values[$key] = round($value, 3);
            }

            if ($values['w'] <= 0 || $values['h'] <= 0 || $values['x'] + $values['w'] > 100.01 || $values['y'] + $values['h'] > 100.01) {
                throw new MediaRejectedException(__('De uitsnede valt buiten het beeld.'));
            }

            // De hele afbeelding is geen uitsnede.
            $crop = $values['x'] <= 0.0 && $values['y'] <= 0.0 && $values['w'] >= 99.999 && $values['h'] >= 99.999 ? null : $values;
        }

        return ['rotate' => (int) $rotate, 'flip' => $flip, 'crop' => $crop];
    }

    /**
     * @param  array{rotate: int, flip: ?string, crop: ?array}  $recipe  al gevalideerd
     */
    public static function isIdentity(array $recipe): bool
    {
        return $recipe['rotate'] === 0 && $recipe['flip'] === null && $recipe['crop'] === null;
    }

    /**
     * Voert het recept uit op een lokale kopie van de bron en geeft het pad
     * van het resultaat terug (in storage/app/media-ingest; de aanroeper
     * ruimt het op).
     *
     * @param  array{rotate: int, flip: ?string, crop: ?array}  $recipe  al gevalideerd
     *
     * @throws MediaRejectedException
     */
    public static function render(string $sourcePath, string $mime, array $recipe): string
    {
        $format = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;

        if ($format === null) {
            throw new MediaRejectedException(__('Alleen een jpeg, png of webp kan worden bewerkt.'));
        }

        $target = IncomingMedia::temporaryPath();

        try {
            // loadFile() zet een foto die volgens zijn EXIF gedraaid is
            // eerst recht: zo ziet de browser hem ook.
            $image = Image::useImageDriver(MediaCapabilities::imageDriver())->loadFile($sourcePath);

            if ($recipe['rotate'] !== 0) {
                $image->orientation(Orientation::from($recipe['rotate']));

                // GD maakt bij het draaien een nieuw beeld en vergeet daarbij
                // dat het alfakanaal bewaard moet worden: een transparante
                // png zou een zwarte achtergrond krijgen.
                if (MediaCapabilities::imageDriver() === 'gd' && method_exists($image, 'image') && ($resource = $image->image()) instanceof \GdImage) {
                    imagealphablending($resource, false);
                    imagesavealpha($resource, true);
                }
            }

            if ($recipe['flip'] !== null) {
                $image->flip(match ($recipe['flip']) {
                    'h' => FlipDirection::Horizontal,
                    'v' => FlipDirection::Vertical,
                    default => FlipDirection::Both,
                });
            }

            if ($recipe['crop'] !== null) {
                $width = $image->getWidth();
                $height = $image->getHeight();
                $x = (int) round($width * $recipe['crop']['x'] / 100);
                $y = (int) round($height * $recipe['crop']['y'] / 100);
                $w = min($width - $x, (int) round($width * $recipe['crop']['w'] / 100));
                $h = min($height - $y, (int) round($height * $recipe['crop']['h'] / 100));

                if ($w < self::MIN_PIXELS || $h < self::MIN_PIXELS) {
                    throw new MediaRejectedException(__('De uitsnede is te klein: minimaal :min bij :min pixels.', ['min' => self::MIN_PIXELS]));
                }

                $image->manualCrop($w, $h, $x, $y);
            }

            $image->format($format);

            if ($format !== 'png') {
                $image->quality(90);
            }

            $image->save($target);
        } catch (MediaRejectedException $exception) {
            @unlink($target);

            throw $exception;
        } catch (Throwable $exception) {
            @unlink($target);
            report($exception);

            throw new MediaRejectedException(__('De afbeelding kon niet worden bewerkt.'), 0, $exception);
        }

        if (! is_file($target) || filesize($target) === 0) {
            @unlink($target);

            throw new MediaRejectedException(__('De afbeelding kon niet worden bewerkt.'));
        }

        return $target;
    }
}
