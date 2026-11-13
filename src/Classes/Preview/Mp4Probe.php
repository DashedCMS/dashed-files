<?php

namespace Dashed\DashedFiles\Classes\Preview;

use Throwable;

/**
 * Duur en afmetingen van een mp4, mov of m4v, in pure PHP: voor een server
 * zonder ffprobe. Loopt alleen de koppen van de boxen af (lengte en type)
 * en leest van `mvhd`, `tkhd` en `hdlr` de eerste bytes; de inhoud van het
 * bestand wordt nooit geladen. De `moov`-box kan vooraan of achteraan staan.
 *
 * Het bestand komt van een gebruiker. Elke lus is begrensd (aantal boxen,
 * diepte), elke lengte wordt tegen de omringende box gehouden, en wat er
 * ook in staat: de uitkomst is een array of null, nooit een exception.
 */
final class Mp4Probe
{
    public const MAX_BOXES = 4000;

    public const MAX_DEPTH = 8;

    // Zoveel mag er van een stroom die niet kan terugspoelen (een
    // objectopslag) vooruit gelezen worden om bij de volgende box te komen.
    public const MAX_FORWARD_BYTES = 16 * 1024 * 1024;

    private const CONTAINERS = ['moov' => true, 'trak' => true, 'mdia' => true];

    /** @var resource */
    private $stream;

    private bool $seekable;

    private int $position = 0;

    private int $boxes = 0;

    private int $forwarded = 0;

    private ?float $duration = null;

    /** @var array<int, array{width: ?int, height: ?int, video: bool}> */
    private array $tracks = [];

    /**
     * @param  resource  $stream
     */
    private function __construct($stream)
    {
        $this->stream = $stream;
        $this->seekable = (bool) (stream_get_meta_data($stream)['seekable'] ?? false);
    }

    /**
     * @return array{width: ?int, height: ?int, duration: ?float}|null null als er geen bruikbare kop gevonden is
     */
    public static function probe(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $stream = @fopen($path, 'rb');

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $size = @filesize($path);

            return self::probeStream($stream, $size === false ? null : (int) $size);
        } finally {
            @fclose($stream);
        }
    }

    /**
     * Voor een stroom vanaf zijn begin, ook een die niet kan terugspoelen.
     * De aanroeper sluit de stroom.
     *
     * @param  resource  $stream
     * @return array{width: ?int, height: ?int, duration: ?float}|null
     */
    public static function probeStream($stream, ?int $size = null): ?array
    {
        if (! is_resource($stream)) {
            return null;
        }

        try {
            $probe = new self($stream);
            $found = $probe->walk(0, $size, 0);

            if (! $found && $probe->duration === null && $probe->tracks === []) {
                return null;
            }

            return $probe->result();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Loopt de boxen tussen $start en $end af. Geeft terug of er een
     * `moov` gezien is; daarna valt er niets meer te halen.
     */
    private function walk(int $start, ?int $end, int $depth): bool
    {
        $offset = $start;
        $sawMoov = false;

        while ($this->boxes < self::MAX_BOXES) {
            if ($end !== null && $offset + 8 > $end) {
                break;
            }

            $header = $this->readAt($offset, 8);

            if ($header === null) {
                break;
            }

            $this->boxes++;
            $length = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerSize = 8;

            if ($length === 1) {
                // De echte lengte staat in de acht bytes erna.
                $large = $this->readAt($offset + 8, 8);

                if ($large === null) {
                    break;
                }

                $parts = unpack('Nhigh/Nlow', $large);

                // Groter dan PHP kan tellen is geen bestand.
                if ($parts['high'] > 0x7FFFFF) {
                    break;
                }

                $length = ($parts['high'] << 32) | $parts['low'];
                $headerSize = 16;
            } elseif ($length === 0) {
                // Tot het einde van het bestand: alleen zinvol als we dat einde kennen.
                if ($end === null) {
                    break;
                }

                $length = $end - $offset;
            }

            // Korter dan zijn eigen kop, of langer dan de box eromheen: kapot.
            if ($length < $headerSize || ($end !== null && $offset + $length > $end)) {
                break;
            }

            $bodyStart = $offset + $headerSize;
            $bodyEnd = $offset + $length;

            if (isset(self::CONTAINERS[$type])) {
                if ($depth < self::MAX_DEPTH) {
                    if ($type === 'trak') {
                        $this->tracks[] = ['width' => null, 'height' => null, 'video' => false];
                    }

                    $this->walk($bodyStart, $bodyEnd, $depth + 1);
                }

                if ($type === 'moov') {
                    $sawMoov = true;

                    // Alles wat we zoeken staat in de moov: klaar.
                    break;
                }
            } elseif ($type === 'mvhd' && $this->duration === null) {
                $this->readMovieHeader($bodyStart, $bodyEnd);
            } elseif ($type === 'tkhd' && $this->tracks !== []) {
                $this->readTrackHeader($bodyStart, $bodyEnd);
            } elseif ($type === 'hdlr' && $this->tracks !== []) {
                $body = $bodyEnd - $bodyStart >= 12 ? $this->readAt($bodyStart, 12) : null;

                if ($body !== null && substr($body, 8, 4) === 'vide') {
                    $this->tracks[array_key_last($this->tracks)]['video'] = true;
                }
            }

            // Een box van lengte nul kan hier niet meer voorkomen, dus de lus komt altijd verder.
            $offset = $bodyEnd;
        }

        return $sawMoov;
    }

    private function readMovieHeader(int $start, int $end): void
    {
        $head = $end - $start >= 4 ? $this->readAt($start, 4) : null;

        if ($head === null) {
            return;
        }

        $version = ord($head[0]);

        if ($version === 0) {
            $body = $end - $start >= 20 ? $this->readAt($start + 4, 16) : null;
            $values = $body === null ? null : unpack('Ncreated/Nmodified/Ntimescale/Nduration', $body);
            $duration = $values === null || $values['duration'] === 0xFFFFFFFF ? null : $values['duration'];
        } elseif ($version === 1) {
            $body = $end - $start >= 32 ? $this->readAt($start + 4, 28) : null;
            $values = $body === null ? null : unpack('N2created/N2modified/Ntimescale/Nhigh/Nlow', $body);
            // Een duur die niet in 53 bits past is "onbekend" of onzin.
            $duration = $values === null || $values['high'] > 0x1FFFFF ? null : ($values['high'] * 4294967296) + $values['low'];
        } else {
            return;
        }

        if ($values === null || $duration === null || $values['timescale'] <= 0) {
            return;
        }

        $this->duration = PreviewNumbers::duration($duration / $values['timescale']);
    }

    private function readTrackHeader(int $start, int $end): void
    {
        $head = $end - $start >= 4 ? $this->readAt($start, 4) : null;

        if ($head === null) {
            return;
        }

        // Versie 0 heeft tijden van vier bytes, versie 1 van acht.
        $skip = match (ord($head[0])) {
            0 => 4 + 20,
            1 => 4 + 32,
            default => null,
        };

        if ($skip === null || $end - $start < $skip + 60) {
            return;
        }

        // Na de tijden: 8 gereserveerd, laag, groep, volume, 2 gereserveerd,
        // dan de matrix (9 getallen) en breedte en hoogte als 16.16.
        $body = $this->readAt($start + $skip + 16, 44);

        if ($body === null) {
            return;
        }

        $matrix = array_values(unpack('N9', substr($body, 0, 36)));
        $size = unpack('Nwidth/Nheight', substr($body, 36, 8));
        $width = PreviewNumbers::dimension($size['width'] >> 16);
        $height = PreviewNumbers::dimension($size['height'] >> 16);

        // Een kwartslag gedraaid (een staande opname van een telefoon): a en d
        // zijn nul, b en c niet. Dan is het beeld zoals het getoond wordt andersom.
        if ($matrix[0] === 0 && $matrix[4] === 0 && $matrix[1] !== 0 && $matrix[3] !== 0) {
            [$width, $height] = [$height, $width];
        }

        $last = array_key_last($this->tracks);
        $this->tracks[$last]['width'] = $width;
        $this->tracks[$last]['height'] = $height;
    }

    /**
     * @return array{width: ?int, height: ?int, duration: ?float}
     */
    private function result(): array
    {
        foreach ($this->tracks as $track) {
            if ($track['video'] && $track['width'] !== null && $track['height'] !== null) {
                return ['width' => $track['width'], 'height' => $track['height'], 'duration' => $this->duration];
            }
        }

        return ['width' => null, 'height' => null, 'duration' => $this->duration];
    }

    /**
     * Leest $length bytes op $offset, of null als die er niet zijn. Een
     * stroom die niet kan terugspoelen wordt alleen vooruit gelezen, tot
     * een grens.
     */
    private function readAt(int $offset, int $length): ?string
    {
        if ($offset < 0 || $length <= 0 || $length > 4096) {
            return null;
        }

        if ($offset !== $this->position) {
            if ($this->seekable) {
                if (@fseek($this->stream, $offset) !== 0) {
                    return null;
                }
            } else {
                $gap = $offset - $this->position;

                if ($gap < 0 || $this->forwarded + $gap > self::MAX_FORWARD_BYTES) {
                    return null;
                }

                while ($gap > 0) {
                    $chunk = @fread($this->stream, min(65536, $gap));

                    if ($chunk === false || $chunk === '') {
                        return null;
                    }

                    $gap -= strlen($chunk);
                    $this->forwarded += strlen($chunk);
                }
            }

            $this->position = $offset;
        }

        $data = '';

        while (strlen($data) < $length) {
            $chunk = @fread($this->stream, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
        }

        $this->position += strlen($data);

        return strlen($data) === $length ? $data : null;
    }
}
