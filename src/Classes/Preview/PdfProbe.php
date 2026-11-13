<?php

namespace Dashed\DashedFiles\Classes\Preview;

use Throwable;

/**
 * Het aantal pagina's van een pdf, in pure PHP, voor het eenvoudige geval:
 * de wortel van de paginaboom staat leesbaar in het bestand
 * (`/Type /Pages ... /Count N`) of het bestand is gelineariseerd
 * (`/Linearized ... /N N`). Leest alleen het begin en het eind van het
 * bestand, begrensd. Staat de boom in een object stream (gecomprimeerd), of
 * is er twijfel, dan null: liever geen getal dan een verkeerd getal.
 */
final class PdfProbe
{
    public const HEAD_BYTES = 256 * 1024;

    public const TAIL_BYTES = 512 * 1024;

    // Zo ver leest pagesOfStream() een stroom die niet kan spoelen door om bij de staart te komen.
    public const SKIP_BYTES = 32 * 1024 * 1024;

    public static function pages(string $path): ?int
    {
        try {
            return self::read($path);
        } catch (Throwable) {
            return null;
        }
    }

    private static function read(string $path): ?int
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $size = (int) @filesize($path);
        $stream = @fopen($path, 'rb');

        if ($size < 32 || ! is_resource($stream)) {
            return null;
        }

        try {
            $whole = $size <= self::HEAD_BYTES + self::TAIL_BYTES;
            $head = (string) fread($stream, $whole ? $size : self::HEAD_BYTES);
            $tail = '';

            if (! $whole && fseek($stream, $size - self::TAIL_BYTES) === 0) {
                $tail = (string) fread($stream, self::TAIL_BYTES);
            }
        } finally {
            fclose($stream);
        }

        return self::fromParts($head, $tail, $whole);
    }

    /**
     * Hetzelfde voor een stroom vanaf zijn begin (een bestand op een
     * schijf op afstand): alleen de kop en de staart worden gelezen, het
     * bestand komt nergens op de server te staan. Een stroom die niet kan
     * spoelen wordt tot hooguit SKIP_BYTES doorgelezen om bij de staart te
     * komen; is het bestand groter, dan telt alleen de kop. De aanroeper
     * sluit de stroom.
     *
     * @param  resource  $stream
     */
    public static function pagesOfStream($stream, ?int $size): ?int
    {
        try {
            if (! is_resource($stream) || $size === null || $size < 32) {
                return null;
            }

            $whole = $size <= self::HEAD_BYTES + self::TAIL_BYTES;
            $head = self::readExactly($stream, $whole ? $size : self::HEAD_BYTES);
            $tail = '';

            if (! $whole) {
                $offset = $size - self::TAIL_BYTES;

                if ((bool) (stream_get_meta_data($stream)['seekable'] ?? false) && @fseek($stream, $offset) === 0) {
                    $tail = self::readExactly($stream, self::TAIL_BYTES);
                } elseif ($size <= self::SKIP_BYTES) {
                    $toSkip = $offset - strlen($head);

                    while ($toSkip > 0 && ! feof($stream)) {
                        $chunk = fread($stream, min(65536, $toSkip));

                        if ($chunk === false || $chunk === '') {
                            break;
                        }

                        $toSkip -= strlen($chunk);
                    }

                    $tail = $toSkip === 0 ? self::readExactly($stream, self::TAIL_BYTES) : '';
                }
            }

            return self::fromParts($head, $tail, $whole);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  resource  $stream */
    private static function readExactly($stream, int $bytes): string
    {
        $data = '';

        while (strlen($data) < $bytes && ! feof($stream)) {
            $chunk = fread($stream, min(65536, $bytes - strlen($data)));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
        }

        return $data;
    }

    private static function fromParts(string $head, string $tail, bool $whole): ?int
    {
        if (! str_contains(substr($head, 0, 1024), '%PDF-')) {
            return null;
        }

        // Gelineariseerd: het eerste object draagt het aantal pagina's.
        if (preg_match('/<<[^<>]{0,400}\/Linearized\b[^<>]{0,400}>>/s', substr($head, 0, 4096), $dictionary)
            && preg_match('/\/N\s+(\d{1,7})\b/', $dictionary[0], $match)) {
            return PreviewNumbers::pages($match[1]);
        }

        $counts = [];

        foreach ([$head, $tail] as $part) {
            if ($part === '' || ! preg_match_all('/\/Type\s*\/Pages(?![A-Za-z])/', $part, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            // Hooguit een handvol kandidaten bekijken.
            foreach (array_slice($matches[0], 0, 200) as [, $position]) {
                $dictionary = self::objectAround($part, (int) $position);

                // Een tussenknoop heeft een ouder; de wortel niet.
                if ($dictionary === null || preg_match('/\/Parent\b/', $dictionary)) {
                    continue;
                }

                if (preg_match('/\/Count\s+(\d{1,7})\b/', $dictionary, $match)) {
                    $counts[] = (int) $match[1];
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        // Meer dan één wortel met een ander aantal (een bijgewerkt bestand):
        // alleen als het hele bestand gelezen is weten we welke de laatste is.
        if (count(array_unique($counts)) > 1 && ! $whole) {
            return null;
        }

        return PreviewNumbers::pages(end($counts));
    }

    /** De tekst van het object rond $position: van `obj` tot `endobj`. */
    private static function objectAround(string $data, int $position): ?string
    {
        $from = strrpos(substr($data, max(0, $position - 4096), min(4096, $position)), 'obj');
        $start = $from === false ? null : max(0, $position - 4096) + $from;
        $end = strpos($data, 'endobj', $position);

        if ($start === null || $end === false || $end - $start > 16384) {
            return null;
        }

        $object = substr($data, $start, $end - $start);

        // Een `endobj` tussen begin en vondst: de `obj` hoorde bij een eerder object.
        return str_contains(substr($object, 0, $position - $start), 'endobj') ? null : $object;
    }
}
