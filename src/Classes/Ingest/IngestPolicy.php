<?php

namespace Dashed\DashedFiles\Classes\Ingest;

use finfo;

/**
 * De ene plek die zegt welk bestandstype via welke bron de bibliotheek in
 * mag, en onder welke extensie het bewaard wordt. Alles gaat op het type dat
 * uit de inhoud is gesnoven; de naam van de bron telt alleen mee waar de
 * inhoud zelf geen uitsluitsel geeft (een docx is voor libmagic een zip) en
 * dan alleen voor typen die geen html of script kunnen zijn.
 */
final class IngestPolicy
{
    public const RASTER = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif', 'image/avif',
    ];

    public const SVG = 'image/svg+xml';

    private const PDF = 'application/pdf';

    private const OTHER_RASTER = ['image/bmp', 'image/tiff', 'image/x-icon'];

    // Wat de bibliotheek via het beheer altijd al aannam: afbeeldingen, pdf en video.
    private const LIBRARY = [
        ...self::RASTER,
        ...self::OTHER_RASTER,
        self::SVG,
        self::PDF,
        'video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v', 'video/ogg', 'video/mpeg', 'video/x-msvideo', 'video/3gpp',
    ];

    // Een zip-container met een eigen extensie: libmagic ziet er meestal alleen de zip in.
    private const ZIP_OFFICE = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
    ];

    // Het oude binaire Office-formaat: één container voor doc, xls en ppt.
    private const OLE_OFFICE = [
        'doc' => 'application/msword',
        'xls' => 'application/vnd.ms-excel',
        'ppt' => 'application/vnd.ms-powerpoint',
    ];

    private const OLE_RAW = [
        'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office', 'application/octet-stream',
        'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
    ];

    private const TEXT = ['text/plain', 'text/csv'];

    // Wat een beheerder naast beeld in de bibliotheek zet: handleidingen, prijslijsten, geluid.
    private const DOCUMENTS = [
        ...self::ZIP_OFFICE,
        ...self::OLE_OFFICE,
        'application/rtf',
        ...self::TEXT,
        'application/zip',
        'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/aac', 'audio/flac',
    ];

    private const FONTS = ['font/woff', 'font/woff2', 'font/ttf', 'font/otf'];

    public const ALLOWED = [
        'upload' => [...self::LIBRARY, ...self::DOCUMENTS],
        // Wat code er zelf in zet (een import, een thema): ook lettertypen.
        'system' => [...self::LIBRARY, ...self::DOCUMENTS, ...self::FONTS],
        'chat' => [...self::RASTER, self::PDF],
        'api' => [...self::RASTER, self::PDF],
        'url' => self::RASTER,
        'ai' => self::RASTER,
        'stock' => self::RASTER,
    ];

    // Ook niet via config of allowedMimes: de publieke schijf levert het
    // inhoudstype uit, dus dit zou in de browser van een bezoeker draaien.
    private const NEVER_EXACT = [
        'text/html', 'application/xhtml+xml',
        'application/x-msdownload', 'application/x-dosexec', 'application/x-msdos-program',
        'application/vnd.microsoft.portable-executable', 'application/x-executable', 'application/x-pie-executable',
        'application/x-sharedlib', 'application/x-mach-binary', 'application/x-elf', 'application/java-archive',
        'application/x-sh', 'application/x-shellscript', 'text/x-shellscript', 'application/x-httpd-php',
        'application/x-httpd-php-source', 'text/x-perl', 'text/x-python', 'text/x-ruby',
    ];

    private const NEVER_CONTAINS = ['javascript', 'ecmascript', 'php'];

    /**
     * Per type de extensies waaronder het bewaard mag worden; de eerste is
     * de standaard als de naam er geen van draagt.
     */
    private const EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/heic' => ['heic', 'heif'],
        'image/heif' => ['heif', 'heic'],
        'image/avif' => ['avif'],
        'image/bmp' => ['bmp'],
        'image/tiff' => ['tif', 'tiff'],
        'image/x-icon' => ['ico'],
        'image/svg+xml' => ['svg'],
        'application/pdf' => ['pdf'],
        'video/mp4' => ['mp4', 'm4v'],
        'video/webm' => ['webm'],
        'video/quicktime' => ['mov', 'qt'],
        'video/x-m4v' => ['m4v', 'mp4'],
        'video/ogg' => ['ogv', 'ogg'],
        'video/mpeg' => ['mpg', 'mpeg'],
        'video/x-msvideo' => ['avi'],
        'video/3gpp' => ['3gp'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/vnd.oasis.opendocument.text' => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
        'application/vnd.oasis.opendocument.presentation' => ['odp'],
        'application/msword' => ['doc'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/rtf' => ['rtf'],
        'text/plain' => ['txt'],
        'text/csv' => ['csv'],
        'application/zip' => ['zip'],
        'audio/mpeg' => ['mp3'],
        'audio/wav' => ['wav'],
        'audio/ogg' => ['ogg', 'oga'],
        'audio/mp4' => ['m4a'],
        'audio/aac' => ['aac'],
        'audio/flac' => ['flac'],
        'font/woff' => ['woff'],
        'font/woff2' => ['woff2'],
        'font/ttf' => ['ttf'],
        'font/otf' => ['otf'],
    ];

    private const ALIASES = [
        'image/jpg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/svg' => self::SVG,
        'image/x-ms-bmp' => 'image/bmp',
        'image/vnd.microsoft.icon' => 'image/x-icon',
        'image/heic-sequence' => 'image/heic',
        'image/heif-sequence' => 'image/heif',
        'application/x-zip-compressed' => 'application/zip',
        'text/rtf' => 'application/rtf',
        'application/csv' => 'text/csv',
        'audio/x-wav' => 'audio/wav',
        'audio/wave' => 'audio/wav',
        'audio/vnd.wave' => 'audio/wav',
        'audio/mp3' => 'audio/mpeg',
        'audio/x-m4a' => 'audio/mp4',
        'audio/x-hx-aac-adts' => 'audio/aac',
        'audio/x-aac' => 'audio/aac',
        'audio/x-flac' => 'audio/flac',
        'application/font-woff' => 'font/woff',
        'application/font-woff2' => 'font/woff2',
        'font/sfnt' => 'font/ttf',
        'application/font-sfnt' => 'font/ttf',
        'application/x-font-ttf' => 'font/ttf',
        'application/vnd.ms-opentype' => 'font/otf',
        'application/x-font-otf' => 'font/otf',
    ];

    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1', 'heif'];

    private const AVIF_BRANDS = ['avif', 'avis'];

    private const SVG_RAW = ['text/xml', 'application/xml', 'text/plain'];

    private const ZIP_MAGIC = "PK\x03\x04";

    private const OLE_MAGIC = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    private const HEAD_BYTES = 64 * 1024;

    private const DEFAULT_MAX_DOWNLOAD_BYTES = 25 * 1024 * 1024;

    private const SYSTEM_MAX_DOWNLOAD_BYTES = 100 * 1024 * 1024;

    /**
     * @return array<int, string>
     */
    public static function allowedFor(string $source, array $narrowTo = []): array
    {
        $key = self::sourceKey($source);

        $allowed = config("dashed-files.ingest.allowed.{$key}", self::ALLOWED[$key] ?? []);
        $allowed = array_map('strtolower', array_filter((array) $allowed, 'is_string'));

        if ($narrowTo !== []) {
            $allowed = array_intersect($allowed, array_map('strtolower', array_filter($narrowTo, 'is_string')));
        }

        return array_values(array_filter(array_unique($allowed), fn (string $mime) => ! self::isNeverAllowed($mime)));
    }

    /**
     * De voorselectie voor de downloader: de lijst zelf plus elk ruw type
     * van libmagic waar refine() een type uit de lijst van kan maken. De
     * downloader kent die correcties niet; het echte oordeel velt ingest().
     *
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    public static function rawTypesFor(array $allowed): array
    {
        $raw = $allowed;

        foreach (self::ALIASES as $from => $to) {
            if (in_array($to, $allowed, true)) {
                $raw[] = $from;
            }
        }

        $has = fn (array $types) => array_intersect($types, $allowed) !== [];

        if ($has(['image/heic', 'image/heif', 'image/avif', 'font/ttf', 'font/otf'])) {
            $raw[] = 'application/octet-stream';
        }

        if ($has([self::SVG])) {
            $raw = array_merge($raw, self::SVG_RAW);
        }

        if ($has(array_values(self::ZIP_OFFICE))) {
            $raw = array_merge($raw, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream']);
        }

        if ($has(array_values(self::OLE_OFFICE))) {
            $raw = array_merge($raw, self::OLE_RAW);
        }

        if ($has(['text/csv'])) {
            $raw[] = 'text/plain';
        }

        return array_values(array_filter(array_unique($raw), fn (string $mime) => ! self::isNeverAllowed($mime)));
    }

    /**
     * Hoe een bestand voor deze bron opgehaald mag worden. Alleen `system`
     * (een adres dat code koos, zoals een import) krijgt meer ruimte dan de
     * standaard van de downloader.
     *
     * @return array<string, mixed>
     */
    public static function downloadOptionsFor(string $source): array
    {
        $key = self::sourceKey($source);
        $system = $key === 'system';

        $maxBytes = config("dashed-files.ingest.max_download_bytes.{$key}");
        $options = [
            'max_bytes' => is_numeric($maxBytes) && $maxBytes > 0 ? (int) $maxBytes : ($system ? self::SYSTEM_MAX_DOWNLOAD_BYTES : self::DEFAULT_MAX_DOWNLOAD_BYTES),
            'allowed_mimes' => self::rawTypesFor(self::allowedFor($source)),
        ];

        if (! $system) {
            return $options;
        }

        $ports = array_values(array_filter(array_map('intval', (array) config('dashed-files.ingest.allowed_ports.system', [80, 443]))));

        return $options + [
            'allow_http' => true,
            'allowed_ports' => $ports === [] ? [80, 443] : $ports,
            'max_redirects' => 5,
            // Risico: voor een host op deze lijst vervalt de toets op interne adressen, dus wie de bron-url van een import kan sturen, kan dit proces die host laten lezen.
            'trusted_hosts' => array_values(array_filter((array) config('dashed-files.ingest.trusted_hosts', []), 'is_string')),
        ];
    }

    public static function isNeverAllowed(string $mime): bool
    {
        $mime = strtolower($mime);

        if (in_array($mime, self::NEVER_EXACT, true)) {
            return true;
        }

        foreach (self::NEVER_CONTAINS as $needle) {
            if (str_contains($mime, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function isRaster(string $mime): bool
    {
        return in_array($mime, self::RASTER, true) || in_array($mime, self::OTHER_RASTER, true);
    }

    /**
     * Het type van het bestand volgens zijn inhoud. De naam van de bron
     * helpt alleen kiezen tussen typen die dezelfde container delen.
     */
    public static function sniff(string $path, string $name = ''): string
    {
        $mime = strtolower((string) @(new finfo(FILEINFO_MIME_TYPE))->file($path));
        $head = (string) @file_get_contents($path, false, null, 0, self::HEAD_BYTES);

        return self::refine($mime, $head, strtolower(pathinfo(self::cleanName($name), PATHINFO_EXTENSION)));
    }

    /**
     * Corrigeert wat libmagic niet of onder een andere naam herkent.
     */
    public static function refine(string $mime, string $head, string $extension = ''): string
    {
        $mime = strtolower($mime);
        $mime = self::ALIASES[$mime] ?? $mime;
        $unknown = in_array($mime, ['application/octet-stream', ''], true);

        // libmagic kent niet elk merk van een HEIC- of AVIF-bestand en geeft
        // dan octet-stream; de ftyp-box aan het begin is de echte aanwijzing.
        if ($unknown && substr($head, 4, 4) === 'ftyp') {
            $brand = strtolower(substr($head, 8, 4));

            if (in_array($brand, self::HEIC_BRANDS, true)) {
                return 'image/heic';
            }

            if (in_array($brand, self::AVIF_BRANDS, true)) {
                return 'image/avif';
            }
        }

        // Een svg zonder xml-declaratie of met commentaar vooraf komt soms
        // als kale xml of tekst terug. Dit is alleen een vermoeden: de
        // opschoner beslist daarna of het echt een bruikbare svg is.
        if (in_array($mime, self::SVG_RAW, true) && preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE\s+svg[^>]*>\s*)?<svg[\s>]/is', $head)) {
            return self::SVG;
        }

        // De naam kiest alleen tussen typen die dezelfde container delen, en
        // alleen als de eerste bytes die container ook echt zijn.
        if (isset(self::ZIP_OFFICE[$extension]) && ($unknown || $mime === 'application/zip') && str_starts_with($head, self::ZIP_MAGIC)) {
            return self::ZIP_OFFICE[$extension];
        }

        if (isset(self::OLE_OFFICE[$extension]) && in_array($mime, self::OLE_RAW, true) && str_starts_with($head, self::OLE_MAGIC)) {
            return self::OLE_OFFICE[$extension];
        }

        if ($unknown && $extension === 'ttf' && (str_starts_with($head, "\x00\x01\x00\x00") || str_starts_with($head, 'true'))) {
            return 'font/ttf';
        }

        if ($unknown && $extension === 'otf' && str_starts_with($head, 'OTTO')) {
            return 'font/otf';
        }

        if (in_array($mime, self::TEXT, true)) {
            if (self::textLooksLikeHtml($head)) {
                return 'text/html';
            }

            // "Tekst" is wat libmagic zegt als het niets herkent. Alleen met
            // een naam die tekst belooft is het ook echt als tekst bedoeld.
            if ($mime === 'text/plain') {
                return match ($extension) {
                    'txt' => 'text/plain',
                    'csv' => 'text/csv',
                    default => 'application/octet-stream',
                };
            }
        }

        return $mime;
    }

    /**
     * Tekst die een browser als pagina zou kunnen opvatten.
     */
    public static function textLooksLikeHtml(string $head): bool
    {
        return str_contains($head, '<') && preg_match('/<(!doctype|html|script|svg|body|iframe)\b/i', $head) === 1;
    }

    /**
     * De toets op servercode voor als dashed-core hem nog niet op een pad
     * kan doen. Zelfde regel als SafeUploadedFile: de korte tag is drie
     * bytes en komt in gecomprimeerde data vanzelf voor, dus daar telt hij
     * alleen als er leesbare code op volgt.
     */
    public static function looksLikeServerCode(string $head, string $mime): bool
    {
        $head = substr($head, 0, self::HEAD_BYTES);

        if (preg_match('/<\?php\b/i', $head) === 1 || str_starts_with($head, '#!')) {
            return true;
        }

        if (! self::isBinary($mime)) {
            return str_contains($head, '<?=');
        }

        // De sluittag staat in twee stukken: aaneen geschreven beëindigt hij de PHP-modus van dit bestand.
        return preg_match('/<\?=(?:[\x09\x0a\x0d\x20-\x7e]{8,}|[\x09\x0a\x0d\x20-\x7e]{0,200}?\?' . '>)/', $head) === 1;
    }

    private static function isBinary(string $mime): bool
    {
        $mime = strtolower($mime);

        if (str_contains($mime, 'svg') || (str_contains($mime, 'xml') && ! str_contains($mime, 'officedocument'))) {
            return false;
        }

        foreach (['image/', 'video/', 'audio/', 'font/'] as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }

        return in_array($mime, [self::PDF, 'application/zip', 'application/gzip', ...array_values(self::ZIP_OFFICE), ...array_values(self::OLE_OFFICE)], true);
    }

    /**
     * Breedte en hoogte van een rasterafbeelding, of null als ze niet te
     * lezen zijn.
     *
     * @return array{width: int, height: int}|null
     */
    public static function dimensions(string $path, string $mime): ?array
    {
        $size = @getimagesize($path);

        if ($size && $size[0] > 0 && $size[1] > 0) {
            return ['width' => (int) $size[0], 'height' => (int) $size[1]];
        }

        // Niet elke PHP-versie leest heic of avif. De ispe-box draagt de
        // afmetingen; een foto uit tegels heeft er meer, de grootste is het beeld.
        if (! in_array($mime, ['image/heic', 'image/heif', 'image/avif'], true)) {
            return null;
        }

        $head = (string) @file_get_contents($path, false, null, 0, 1024 * 1024);
        $best = null;
        $offset = 0;

        while (($offset = strpos($head, 'ispe', $offset)) !== false) {
            $values = strlen($head) >= $offset + 16 ? unpack('Nwidth/Nheight', substr($head, $offset + 8, 8)) : null;

            if ($values && $values['width'] > 0 && $values['height'] > 0 && ($best === null || $values['width'] * $values['height'] > $best['width'] * $best['height'])) {
                $best = ['width' => (int) $values['width'], 'height' => (int) $values['height']];
            }

            $offset += 4;
        }

        return $best;
    }

    public static function maxMegapixels(string $source): float
    {
        $key = self::sourceKey($source);
        $configured = config("dashed-files.ingest.max_megapixels.{$key}");

        if (is_numeric($configured) && $configured > 0) {
            return (float) $configured;
        }

        // `ai` is onvertrouwd, maar een upscale van een gewone foto is al
        // snel 96 megapixel en die aanroep is dan al betaald: de grens van
        // een upload. AiImageOperations weigert een upscale die erboven zou
        // uitkomen vóór de aanroep.
        if ($key === 'ai') {
            return self::AI_MAX_MEGAPIXELS;
        }

        return self::isUntrustedSource($source) ? 50.0 : 150.0;
    }

    public const AI_MAX_MEGAPIXELS = 150.0;

    /**
     * Bronnen waar een anonieme bezoeker of een app het bestand aanlevert,
     * en alles wat van een adres buiten de site komt (een geplakte URL, een
     * stockaanbieder, een AI-dienst): de inhoud is daar door een derde
     * gekozen. Zo'n bestand krijgt de strengere megapixelgrens, wordt
     * geweigerd als de afmetingen niet te lezen zijn en gaat nooit naar een
     * extern programma. Svg en inline komen voor deze bronnen niet voor: hun
     * allowlist kent alleen rasterafbeeldingen.
     */
    public static function isUntrustedSource(string $source): bool
    {
        return in_array(self::sourceKey($source), ['chat', 'api', 'url', 'stock', 'ai'], true);
    }

    /**
     * De naam waaronder het bestand bewaard wordt: de leesbare basisnaam
     * met een extensie die bij de inhoud hoort, of null als er voor dit
     * type geen extensie bekend is.
     */
    public static function fileName(string $name, string $mime): ?string
    {
        $extensions = self::EXTENSIONS[$mime] ?? null;

        if ($extensions === null) {
            return null;
        }

        $name = self::cleanName($name);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stem = $name;

        // Alleen wat op een extensie lijkt gaat eraf; "rapport.2024" of
        // "v1.2 concept" houdt zijn punt.
        if (preg_match('/^(.*)\.([a-z0-9]{1,5})$/i', $name, $match) && preg_match('/[a-z]/i', $match[2])) {
            $stem = $match[1];
        }

        // Dezelfde vervanging die Spatie bij het opslaan doet, zodat de naam
        // die hier uitkomt de naam is waarop later gezocht wordt.
        $stem = str_replace(['#', '/', ' '], '-', $stem);
        // Op bytes en niet op tekens: de grens van een bestandssysteem is
        // 255 bytes, en 150 emoji zijn er 600. mb_strcut breekt geen teken.
        $stem = trim(mb_strcut($stem, 0, 200, 'UTF-8'), " .-\t");

        if ($stem === '') {
            $stem = 'bestand';
        }

        return $stem . '.' . (in_array($extension, $extensions, true) ? $extension : $extensions[0]);
    }

    /**
     * De basisnaam zonder pad, zonder querystring en zonder onzichtbare
     * tekens (control characters, zero-width, soft hyphen).
     */
    private static function cleanName(string $name): string
    {
        $name = (string) preg_split('/[?#]/', str_replace('\\', '/', $name), 2)[0];
        $name = basename($name);

        return (string) preg_replace('/[\p{C}\x{00AD}]+/u', '', mb_scrub($name, 'UTF-8'));
    }

    private static function sourceKey(string $source): string
    {
        // stock:unsplash en stock:pexels delen één instelling.
        return strtolower(explode(':', $source, 2)[0]);
    }
}
