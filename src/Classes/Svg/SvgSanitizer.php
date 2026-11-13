<?php

declare(strict_types=1);

namespace Dashed\DashedFiles\Classes\Svg;

use DOMAttr;
use DOMXPath;
use DOMElement;
use DOMDocument;
use SplObjectStorage;
use InvalidArgumentException;

/**
 * Schoont een SVG op door hem opnieuw op te bouwen: alleen wat op de lijsten
 * hieronder staat wordt overgenomen in een nieuw document. Er wordt dus nooit
 * iets "weggehaald" uit de invoer, en wat de opschoner niet kent bestaat in de
 * uitvoer niet. Elke waarde die een verwijzing kan bevatten (href, CSS) wordt
 * niet doorgelaten maar opnieuw geschreven uit de onderdelen die gekeurd zijn,
 * zodat een verschil in lezing tussen deze code en een browser geen gat is.
 */
class SvgSanitizer
{
    /** Ophogen bij elke wijziging van de regels, zodat opgeslagen bestanden opnieuw gekeurd kunnen worden. */
    public const VERSION = 1;

    public const PROFILE_FILE = 'file';

    public const PROFILE_INLINE = 'inline';

    public const MAX_BYTES = 2097152;

    public const MAX_ELEMENTS = 20000;

    /** Hoeveel verwijzingen (use, fill, mask, filter, marker, ...) achter elkaar gevolgd mogen worden. */
    public const MAX_REFERENCE_DEPTH = 8;

    /** Gewicht dat een renderer moet tekenen nadat elke verwijzing is uitgeschreven. */
    public const MAX_EXPANDED_ELEMENTS = 100000;

    /** Grootste breedte of hoogte in pixels, voor de wortel, voor dimensions() en voor een filtergebied. */
    public const MAX_DIMENSION = 10000;

    /** Grootste oppervlak dat dimensions() teruggeeft: 50 megapixel. */
    public const MAX_AREA = 50000000;

    public const MAX_RASTER_DIMENSION = 8000;

    public const MAX_RASTER_AREA = 40000000;

    /** Tekens in aria-label, title en desc. */
    public const MAX_TEXT_LENGTH = 1000;

    private const MAX_CSS_NESTING = 8;

    private const MAX_FILTER_PRIMITIVES = 20;

    private const MAX_OCTAVES = 8;

    private const MAX_BLUR = 250;

    private const MAX_MORPHOLOGY_RADIUS = 100;

    private const MAX_CONVOLUTION_ORDER = 9;

    private const MAX_DASHES = 8;

    private const MIN_DASH = 0.01;

    /** Seconden; alleen voor een animatie die zich herhaalt. */
    private const MIN_REPEATING_DURATION = 0.1;

    private const MAX_GIF_FRAMES = 500;

    /** Stylesheetselectors met een url(#id) erachter; elk element wordt tegen elk daarvan gehouden. */
    private const MAX_STYLE_REFERENCES = 200;

    private const NS_SVG = 'http://www.w3.org/2000/svg';

    private const NS_XLINK = 'http://www.w3.org/1999/xlink';

    private const NS_XML = 'http://www.w3.org/XML/1998/namespace';

    private const NS_XMLNS = 'http://www.w3.org/2000/xmlns/';

    // Zonder dubbele punt: die zit niet in de toegestane tekens van een CSS-waarde,
    // en zo kan een id nooit op een schema lijken.
    private const ID = '[A-Za-z0-9_.\-]{1,200}';

    private const MAX_CSS_VALUE_LENGTH = 4096;

    /**
     * Exacte schrijfwijze. Een HTML-parser maakt van <SCRIPT> of <foreignobject>
     * weer het echte element, dus een afwijkende schrijfwijze is geen onschuldige
     * onbekende maar een poging en valt buiten de lijst.
     */
    private const ELEMENTS = [
        'svg' => true, 'g' => true, 'defs' => true, 'symbol' => true, 'use' => true, 'switch' => true,
        'title' => true, 'desc' => true,
        'path' => true, 'rect' => true, 'circle' => true, 'ellipse' => true, 'line' => true,
        'polyline' => true, 'polygon' => true,
        'text' => true, 'tspan' => true, 'textPath' => true,
        'linearGradient' => true, 'radialGradient' => true, 'stop' => true, 'pattern' => true,
        'clipPath' => true, 'mask' => true, 'marker' => true,
        'image' => true, 'a' => true, 'style' => true,
        'filter' => true, 'feBlend' => true, 'feColorMatrix' => true, 'feComponentTransfer' => true,
        'feComposite' => true, 'feConvolveMatrix' => true, 'feDiffuseLighting' => true,
        'feDisplacementMap' => true, 'feDistantLight' => true, 'feDropShadow' => true, 'feFlood' => true,
        'feFuncA' => true, 'feFuncB' => true, 'feFuncG' => true, 'feFuncR' => true,
        'feGaussianBlur' => true, 'feImage' => true, 'feMerge' => true, 'feMergeNode' => true,
        'feMorphology' => true, 'feOffset' => true, 'fePointLight' => true, 'feSpecularLighting' => true,
        'feSpotLight' => true, 'feTile' => true, 'feTurbulence' => true,
        'animate' => true, 'animateTransform' => true, 'animateMotion' => true, 'set' => true, 'mpath' => true,
    ];

    private const ANIMATION_ELEMENTS = [
        'animate' => true, 'animateTransform' => true, 'animateMotion' => true, 'set' => true, 'mpath' => true,
    ];

    /** Alleen hier blijft tekst staan; elders in een SVG wordt tekst niet getekend. */
    private const TEXT_ELEMENTS = [
        'text' => true, 'tspan' => true, 'textPath' => true, 'title' => true, 'desc' => true, 'a' => true,
    ];

    private const FRAGMENT_HREF_ELEMENTS = [
        'a' => true, 'use' => true, 'linearGradient' => true, 'radialGradient' => true, 'pattern' => true,
        'filter' => true, 'textPath' => true, 'mpath' => true, 'feImage' => true,
        'animate' => true, 'animateTransform' => true, 'animateMotion' => true, 'set' => true,
    ];

    private const DATA_HREF_ELEMENTS = ['image' => true, 'feImage' => true];

    /** Attributen zonder namespace die geen CSS-waarde dragen. */
    private const ATTRIBUTES = [
        'id' => true, 'class' => true, 'style' => true, 'lang' => true, 'role' => true,
        'aria-label' => true, 'aria-labelledby' => true, 'aria-describedby' => true, 'aria-hidden' => true,
        'version' => true, 'viewBox' => true, 'preserveAspectRatio' => true, 'systemLanguage' => true,
        'x' => true, 'y' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true,
        'cx' => true, 'cy' => true, 'r' => true, 'rx' => true, 'ry' => true,
        'width' => true, 'height' => true, 'd' => true, 'points' => true, 'dx' => true, 'dy' => true,
        'rotate' => true, 'pathLength' => true,
        'textLength' => true, 'lengthAdjust' => true, 'startOffset' => true, 'method' => true,
        'spacing' => true, 'side' => true,
        'gradientUnits' => true, 'spreadMethod' => true, 'fx' => true, 'fy' => true, 'fr' => true, 'offset' => true,
        'patternUnits' => true, 'patternContentUnits' => true,
        'clipPathUnits' => true, 'maskUnits' => true, 'maskContentUnits' => true,
        'markerWidth' => true, 'markerHeight' => true, 'markerUnits' => true, 'refX' => true, 'refY' => true,
        'orient' => true,
        'filterUnits' => true, 'primitiveUnits' => true, 'in' => true, 'in2' => true, 'result' => true,
        'stdDeviation' => true, 'mode' => true, 'type' => true, 'values' => true, 'tableValues' => true,
        'slope' => true, 'intercept' => true, 'amplitude' => true, 'exponent' => true, 'operator' => true,
        'k1' => true, 'k2' => true, 'k3' => true, 'k4' => true, 'order' => true, 'kernelMatrix' => true,
        'divisor' => true, 'bias' => true, 'targetX' => true, 'targetY' => true, 'edgeMode' => true,
        'kernelUnitLength' => true, 'preserveAlpha' => true, 'surfaceScale' => true,
        'diffuseConstant' => true, 'specularConstant' => true, 'specularExponent' => true,
        'limitingConeAngle' => true, 'pointsAtX' => true, 'pointsAtY' => true, 'pointsAtZ' => true,
        'azimuth' => true, 'elevation' => true, 'z' => true, 'scale' => true,
        'xChannelSelector' => true, 'yChannelSelector' => true, 'radius' => true,
        'baseFrequency' => true, 'numOctaves' => true, 'seed' => true, 'stitchTiles' => true,
    ];

    /** Alleen geldig op een animatie-element. */
    private const ANIMATION_ATTRIBUTES = [
        'attributeName' => true, 'attributeType' => true, 'begin' => true, 'dur' => true, 'end' => true,
        'min' => true, 'max' => true, 'restart' => true, 'repeatCount' => true, 'repeatDur' => true,
        'calcMode' => true, 'keyTimes' => true, 'keySplines' => true, 'from' => true, 'to' => true,
        'by' => true, 'additive' => true, 'accumulate' => true, 'path' => true, 'keyPoints' => true,
        'origin' => true,
    ];

    /**
     * Wat een animatie mag aansturen. Bewust een eigen lijst en niet "alles
     * behalve href": een animatie op href, style, class, id of een on*-attribuut
     * zet een waarde die deze opschoner dan nooit gezien heeft.
     */
    private const ANIMATABLE = [
        'x' => true, 'y' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true,
        'cx' => true, 'cy' => true, 'r' => true, 'rx' => true, 'ry' => true, 'fx' => true, 'fy' => true,
        'width' => true, 'height' => true, 'd' => true, 'points' => true, 'dx' => true, 'dy' => true,
        'rotate' => true, 'offset' => true, 'startOffset' => true, 'pathLength' => true, 'viewBox' => true,
        'transform' => true, 'gradientTransform' => true, 'patternTransform' => true,
        'opacity' => true, 'fill' => true, 'fill-opacity' => true, 'stroke' => true, 'stroke-width' => true,
        'stroke-opacity' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true,
        'stop-color' => true, 'stop-opacity' => true, 'flood-color' => true, 'flood-opacity' => true,
        'color' => true, 'visibility' => true, 'display' => true, 'font-size' => true,
        'stdDeviation' => true, 'baseFrequency' => true, 'scale' => true, 'values' => true, 'seed' => true,
    ];

    /**
     * CSS-eigenschappen die in een style-attribuut, een stylesheet of als
     * presentatie-attribuut mogen staan. Alleen wat de tekening zelf bepaalt:
     * position, z-index, width, background en dergelijke ontbreken, omdat een
     * inline SVG daarmee over de rest van de pagina heen gelegd kan worden.
     */
    private const CSS_PROPERTIES = [
        'fill' => true, 'fill-opacity' => true, 'fill-rule' => true,
        'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true,
        'stroke-miterlimit' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true,
        'stroke-opacity' => true,
        'opacity' => true, 'color' => true, 'display' => true, 'visibility' => true, 'overflow' => true,
        'clip-path' => true, 'clip-rule' => true, 'mask' => true, 'filter' => true,
        'marker' => true, 'marker-start' => true, 'marker-mid' => true, 'marker-end' => true,
        'stop-color' => true, 'stop-opacity' => true, 'flood-color' => true, 'flood-opacity' => true,
        'lighting-color' => true,
        'font' => true, 'font-family' => true, 'font-size' => true, 'font-style' => true,
        'font-weight' => true, 'font-variant' => true, 'font-stretch' => true,
        'text-anchor' => true, 'text-decoration' => true, 'letter-spacing' => true, 'word-spacing' => true,
        'dominant-baseline' => true, 'alignment-baseline' => true, 'baseline-shift' => true,
        'writing-mode' => true, 'direction' => true, 'paint-order' => true, 'vector-effect' => true,
        'shape-rendering' => true, 'text-rendering' => true, 'image-rendering' => true,
        'color-interpolation' => true, 'color-interpolation-filters' => true,
        'mix-blend-mode' => true, 'isolation' => true,
        'transform' => true, 'transform-origin' => true, 'transform-box' => true,
    ];

    /** Geen CSS-eigenschap, wel dezelfde waardesyntaxis (functies met haakjes). */
    private const TRANSFORM_ATTRIBUTES = ['gradientTransform' => true, 'patternTransform' => true];

    /** Geen enkele functie hier haalt iets op. url() staat er niet in en wordt apart behandeld. */
    private const CSS_FUNCTIONS = [
        'rgb' => true, 'rgba' => true, 'hsl' => true, 'hsla' => true,
        'matrix' => true, 'translate' => true, 'translatex' => true, 'translatey' => true,
        'scale' => true, 'scalex' => true, 'scaley' => true, 'rotate' => true, 'skewx' => true, 'skewy' => true,
        'blur' => true, 'brightness' => true, 'contrast' => true, 'drop-shadow' => true, 'grayscale' => true,
        'hue-rotate' => true, 'invert' => true, 'opacity' => true, 'saturate' => true, 'sepia' => true,
    ];

    /**
     * Op de buitenste svg van een inline SVG. Dat is in HTML een gewone box:
     * met overflow tekent de inhoud erbuiten, met transform of filter komt de
     * box zelf buiten zijn vak, en met opacity of visibility wordt hij een
     * onzichtbare klikvanger.
     */
    private const INLINE_ROOT_BLOCKED = [
        'overflow' => true, 'transform' => true, 'transform-origin' => true, 'transform-box' => true,
        'filter' => true, 'mask' => true, 'clip-path' => true, 'opacity' => true, 'visibility' => true,
        'mix-blend-mode' => true, 'isolation' => true,
    ];

    /** Elementen waarvan href een doel aanwijst zonder het te tekenen. */
    private const NAVIGATION_ELEMENTS = [
        'a' => true, 'animate' => true, 'animateTransform' => true, 'animateMotion' => true, 'set' => true,
    ];

    private const UNITS_TO_PIXELS = [
        '' => 1.0, 'px' => 1.0, 'pt' => 96 / 72, 'pc' => 16.0, 'in' => 96.0, 'cm' => 96 / 2.54, 'mm' => 96 / 25.4,
    ];

    /** Wordt tijdens het opbouwen gezet door een regel die het hele bestand afkeurt. */
    private bool $refused = false;

    /** @var array<string, DOMElement> */
    private array $targets = [];

    /** @var array{id: array, class: array, tag: array, any: array} */
    private array $styleIndex = ['id' => [], 'class' => [], 'tag' => [], 'any' => []];

    private ?SplObjectStorage $measured = null;

    private ?SplObjectStorage $visiting = null;

    /**
     * @param  string|null  $idPrefix  Alleen voor profiel inline. Leeg of geen veilige naam: afgeleid van de inhoud.
     * @return string|null null betekent weigeren; de aanroeper mag het bestand dan niet opslaan of tonen.
     */
    public function sanitize(string $svg, string $profile = self::PROFILE_FILE, ?string $idPrefix = null): ?string
    {
        if ($profile !== self::PROFILE_FILE && $profile !== self::PROFILE_INLINE) {
            throw new InvalidArgumentException("Unknown SVG sanitizer profile [{$profile}].");
        }

        $root = $this->load($svg);

        if ($root === null) {
            return null;
        }

        if ($profile === self::PROFILE_FILE) {
            return $this->build($root, $profile, '');
        }

        if ($idPrefix === null || ! preg_match('/^[A-Za-z][A-Za-z0-9_\-]{0,40}$/D', $idPrefix)) {
            // Afgeleid van de opgeschoonde vorm en niet van de invoer, zodat het origineel
            // en de al opgeschoonde opslagversie hetzelfde voorvoegsel krijgen.
            $unprefixed = $this->build($root, $profile, '');

            if ($unprefixed === null) {
                return null;
            }

            $idPrefix = 's'.substr(sha1($unprefixed), 0, 10).'-';
        }

        return $this->build($root, $profile, $idPrefix);
    }

    /**
     * @return array{width: int, height: int}|null
     */
    public function dimensions(string $svg): ?array
    {
        $root = $this->load($svg);

        // Ook de begrenzingen: afmetingen van een bestand dat sanitize() weigert bestaan niet.
        if ($root === null || $this->build($root, self::PROFILE_FILE, '') === null) {
            return null;
        }

        $width = $this->toPixels($root->getAttribute('width'));
        $height = $this->toPixels($root->getAttribute('height'));

        if ($width === null || $height === null) {
            $parts = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));

            if (count($parts) !== 4) {
                return null;
            }

            $width = $this->toPixels($parts[2]);
            $height = $this->toPixels($parts[3]);
        }

        // Wie hierop een canvas reserveert, reserveert breedte maal hoogte: ook het oppervlak telt.
        if ($width === null || $height === null || $width * $height > self::MAX_AREA) {
            return null;
        }

        return ['width' => $width, 'height' => $height];
    }

    private function toPixels(string $value): ?int
    {
        if (! preg_match('/^\s*(\d{1,7}(?:\.\d{1,10})?|\.\d{1,10})\s*([A-Za-z]{0,2})\s*$/D', $value, $match)) {
            return null;
        }

        $factor = self::UNITS_TO_PIXELS[strtolower($match[2])] ?? null;
        $pixels = $factor === null ? 0.0 : (float) $match[1] * $factor;

        if ($pixels <= 0 || $pixels > self::MAX_DIMENSION) {
            return null;
        }

        return max(1, (int) round($pixels));
    }

    /**
     * width en height op de wortel bepalen in HTML de box van de SVG en bij het
     * rasteren het canvas. Geen em of rem: die hangen af van een lettergrootte
     * die de SVG zelf kan zetten.
     */
    private function rootLengthIsBounded(string $value): bool
    {
        if (preg_match('/^\s*(\d{1,7}(?:\.\d{1,10})?|\.\d{1,10})(px|pt|pc|in|cm|mm|%)?\s*$/Di', $value, $match) !== 1) {
            return false;
        }

        $number = (float) $match[1];
        $unit = strtolower($match[2] ?? '');

        if ($number <= 0) {
            return false;
        }

        return $unit === '%' ? $number <= 100 : $number * self::UNITS_TO_PIXELS[$unit] <= self::MAX_DIMENSION;
    }

    private function load(string $svg): ?DOMElement
    {
        if ($svg === '' || strlen($svg) > self::MAX_BYTES) {
            return null;
        }

        // svgz: uitpakken zou een tweede groottegrens en een zip-bom-toets vragen.
        if (str_starts_with($svg, "\x1f\x8b")) {
            return null;
        }

        if (str_starts_with($svg, "\xEF\xBB\xBF")) {
            $svg = substr($svg, 3);
        }

        // De tekstuele toetsen hieronder gelden alleen als de parser dezelfde bytes als
        // dezelfde tekens leest. Daarom alleen UTF-8, ook in de declaratie.
        if (str_contains($svg, "\0") || ! mb_check_encoding($svg, 'UTF-8')) {
            return null;
        }

        // De declaratie moet in haar geheel de bekende vorm hebben. Zoeken naar
        // "encoding=" ergens in de declaratie vindt ook een nep-encoding in de waarde van version.
        if (preg_match('/\A<\?xml[\s?]/', $svg) === 1
            && preg_match('/\A<\?xml\s+version\s*=\s*(["\'])1\.0\1(?:\s+encoding\s*=\s*(["\'])utf-8\2)?(?:\s+standalone\s*=\s*(["\'])(?:yes|no)\3)?\s*\?>/i', $svg) !== 1) {
            return null;
        }

        // Een entiteitdeclaratie is nooit nodig voor een tekening en altijd het begin van
        // XXE of billion laughs. Ook in commentaar of CDATA: liever een keer te vaak weigeren.
        if (stripos($svg, '<!ENTITY') !== false) {
            return null;
        }

        $svg = $this->withoutDoctype($svg);

        if ($svg === null) {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            // Bewust zonder LIBXML_NOENT, LIBXML_DTDLOAD en LIBXML_PARSEHUGE: geen
            // entiteitvervanging, geen externe DTD, en de dieptegrens van libxml blijft staan.
            $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // De doctype-toets op de DOM is het vangnet onder de tekstuele toets hierboven.
        if (! $loaded || $document->doctype !== null) {
            return null;
        }

        if ($document->getElementsByTagName('*')->length > self::MAX_ELEMENTS) {
            return null;
        }

        $root = $document->documentElement;

        if ($root === null || $root->namespaceURI !== self::NS_SVG || $root->localName !== 'svg') {
            return null;
        }

        return $root;
    }

    /**
     * Haalt een DOCTYPE zonder interne subset weg (oude exports van Illustrator en
     * Inkscape hebben er standaard een). Er wordt niet gezocht naar "waar de
     * DOCTYPE ophoudt": de hele proloog moet van het eerste teken af precies de
     * verwachte vorm hebben, en alles wat daar niet in past is een weigering.
     * Een subset ([ ... ]) past er niet in, dus ATTLIST-standaardwaarden en
     * entiteiten komen nooit bij de parser.
     */
    private function withoutDoctype(string $svg): ?string
    {
        $count = preg_match_all('/<!DOCTYPE/i', $svg);

        if ($count === 0) {
            return $svg;
        }

        if ($count !== 1) {
            return null;
        }

        $literal = '(?:"[^"]*"|\'[^\']*\')';
        $pattern = '/\A((?:<\?xml\s[^<>?]*\?>)?(?:\s++|<!--(?:[^-]++|-[^-])*+-->)*+)'
            .'<!DOCTYPE\s+[A-Za-z_][A-Za-z0-9_.:\-]*'
            .'(?:\s+PUBLIC\s+'.$literal.'\s+'.$literal.'|\s+SYSTEM\s+'.$literal.')?'
            .'\s*>/';

        // Mislukt de match (ook door een PCRE-limiet), dan is de vorm niet met zekerheid herkend.
        if (preg_match($pattern, $svg, $match) !== 1) {
            return null;
        }

        $stripped = $match[1].substr($svg, strlen($match[0]));

        // Weghalen mag geen nieuwe DOCTYPE laten ontstaan uit wat eromheen stond.
        return stripos($stripped, '<!DOCTYPE') === false ? $stripped : null;
    }

    private function build(DOMElement $source, string $profile, string $prefix): ?string
    {
        $this->refused = false;

        $target = new DOMDocument('1.0', 'UTF-8');
        $root = $target->createElementNS(self::NS_SVG, 'svg');
        $target->appendChild($root);

        // Vooraf op de wortel, anders herhaalt libxml de declaratie op elk element met xlink:href.
        $root->setAttributeNS(self::NS_XMLNS, 'xmlns:xlink', self::NS_XLINK);

        $this->fill($root, $source, $target, $profile, $prefix, true);

        if ($this->refused) {
            return null;
        }

        $xpath = new DOMXPath($target);
        $xpath->registerNamespace('xl', self::NS_XLINK);

        if ($xpath->query('//@xl:*')->length === 0) {
            $root->removeAttributeNS(self::NS_XLINK, 'xlink');
        }

        // Op de uitvoer en niet op de invoer: dit is de boom die getekend gaat worden.
        if (! $this->expansionIsBounded($root)) {
            return null;
        }

        $output = $target->saveXML($root);

        // Escapen kan de uitvoer groter maken dan de invoer. Wat hier doorheen komt moet
        // bij een herkeuring (na een nieuwe VERSION) zelf weer geaccepteerd worden.
        return $output === false || strlen($output) > self::MAX_BYTES ? null : $output;
    }

    private function rebuild(DOMElement $source, DOMDocument $target, string $profile, string $prefix): ?DOMElement
    {
        $name = $source->localName;

        // Het voorvoegsel doet er niet toe, de namespace wel: <x:script> in de
        // SVG-namespace is gewoon script, en script in de XHTML-namespace wordt
        // in een SVG-document ook uitgevoerd.
        if ($source->namespaceURI !== self::NS_SVG || ! isset(self::ELEMENTS[$name])) {
            return null;
        }

        $isAnimation = isset(self::ANIMATION_ELEMENTS[$name]);

        // Inline deelt de SVG de pagina: een stylesheet zou de hele site stylen, en
        // animatie verandert attributen nadat ze gekeurd zijn.
        if ($profile === self::PROFILE_INLINE && ($isAnimation || $name === 'style')) {
            return null;
        }

        if ($name === 'style') {
            return $this->rebuildStyle($source, $target);
        }

        if ($isAnimation && ! $this->animationIsSafe($source)) {
            return null;
        }

        $element = $target->createElementNS(self::NS_SVG, $name);
        $this->fill($element, $source, $target, $profile, $prefix, false);

        // title en desc zijn voor een HTML-parser integratiepunten: de inhoud wordt
        // daar als HTML gelezen. Alleen tekst dus, en geen lege huls.
        if (($name === 'title' || $name === 'desc') && trim($element->textContent) === '') {
            return null;
        }

        // Een kernel die niet bij de order past is een fout in het filter; wat een
        // renderer dan doet verschilt. De primitieve gaat dan in zijn geheel weg.
        if ($name === 'feConvolveMatrix' && ! $this->convolutionIsConsistent($element)) {
            return null;
        }

        if ($name === 'filter') {
            $primitives = 0;

            foreach ($element->childNodes as $child) {
                $primitives += $child instanceof DOMElement && str_starts_with($child->localName, 'fe') ? 1 : 0;
            }

            // Elke primitieve is een volledige bewerking over het filtergebied.
            if ($primitives > self::MAX_FILTER_PRIMITIVES) {
                $this->refused = true;
            }
        }

        return $element;
    }

    private function convolutionIsConsistent(DOMElement $element): bool
    {
        $order = preg_split('/[\s,]+/', trim($element->getAttribute('order')), -1, PREG_SPLIT_NO_EMPTY);
        $columns = (int) ($order[0] ?? 3);
        $rows = (int) ($order[1] ?? $columns);
        $kernel = preg_split('/[\s,]+/', trim($element->getAttribute('kernelMatrix')), -1, PREG_SPLIT_NO_EMPTY);

        return $columns >= 1 && $rows >= 1 && count($kernel) === $columns * $rows;
    }

    private function fill(DOMElement $element, DOMElement $source, DOMDocument $target, string $profile, string $prefix, bool $isRoot): void
    {
        $name = $source->localName;
        $hrefs = [];
        $conflict = false;

        // Zonder sleutels: met sleutels overschrijft een attribuut uit een andere
        // namespace het attribuut met dezelfde lokale naam nog voor het gekeurd is.
        foreach (iterator_to_array($source->attributes, false) as $attribute) {
            $namespace = $attribute->namespaceURI;

            if ($attribute->localName === 'href' && ($namespace === null || $namespace === '' || $namespace === self::NS_XLINK)) {
                $key = $namespace === self::NS_XLINK ? 'xlink:href' : 'href';
                $conflict = $conflict || array_key_exists($key, $hrefs);
                $hrefs[$key] = $this->cleanHref($name, $attribute->value, $prefix);

                continue;
            }

            $clean = $this->cleanAttribute($name, $attribute, $profile, $prefix, $isRoot);

            if ($clean === null) {
                continue;
            }

            [$namespace, $qualifiedName, $value] = $clean;

            if ($namespace === null) {
                $element->setAttribute($qualifiedName, $value);
            } else {
                $element->setAttributeNS($namespace, $qualifiedName, $value);
            }
        }

        // SVG 2 laat href winnen, oudere renderers kennen alleen xlink:href. Wijzen ze
        // naar iets verschillends, dan is niet te zeggen wat er getekend wordt: beide weg.
        if (count($hrefs) === 2 && $hrefs['href'] !== $hrefs['xlink:href']) {
            $conflict = true;
        }

        foreach ($conflict ? [] : $hrefs as $key => $href) {
            if ($href === null) {
                continue;
            }

            if ($key === 'href') {
                $element->setAttribute('href', $href);
            } else {
                $element->setAttributeNS(self::NS_XLINK, 'xlink:href', $href);
            }
        }

        // De buitenste svg is in HTML een gewone box; zonder dit tekent de inhoud
        // buiten die box, over de rest van de pagina.
        if ($isRoot && $profile === self::PROFILE_INLINE) {
            $element->setAttribute('overflow', 'hidden');
        }

        $keepsText = isset(self::TEXT_ELEMENTS[$name]);
        $textOnly = $name === 'title' || $name === 'desc';
        $text = '';

        foreach ($source->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $rebuilt = $textOnly ? null : $this->rebuild($child, $target, $profile, $prefix);

                if ($rebuilt !== null) {
                    $element->appendChild($rebuilt);
                }

                continue;
            }

            // Commentaar, processing instructions en elk ander soort node vallen hier
            // vanzelf af. CDATA wordt gewone tekst, zodat de serialisatie hem escapet.
            if (! $keepsText || ($child->nodeType !== XML_TEXT_NODE && $child->nodeType !== XML_CDATA_SECTION_NODE)) {
                continue;
            }

            $value = (string) $child->nodeValue;

            if ($textOnly) {
                $text .= strlen($text) < 4 * self::MAX_TEXT_LENGTH ? $value : '';
            } elseif ($value !== '') {
                // Een lege tekstknoop geeft <a></a>, en die leest een tweede ronde als <a/>.
                $element->appendChild($target->createTextNode($value));
            }
        }

        if ($textOnly && $text !== '') {
            $element->appendChild($target->createTextNode(mb_substr($text, 0, self::MAX_TEXT_LENGTH)));
        }
    }

    /**
     * @return array{0: string|null, 1: string, 2: string}|null namespace, naam en waarde, of null om het attribuut te laten vallen
     */
    private function cleanAttribute(string $element, DOMAttr $attribute, string $profile, string $prefix, bool $isRoot): ?array
    {
        $namespace = $attribute->namespaceURI;
        $name = $attribute->localName;
        $value = $attribute->value;
        $inlineRoot = $isRoot && $profile === self::PROFILE_INLINE;

        // Staat ook niet op de lijst, maar deze regel mag nooit van de lijst afhangen.
        if (strncasecmp($name, 'on', 2) === 0) {
            return null;
        }

        if ($namespace === self::NS_XML) {
            return match (true) {
                $name === 'space' && in_array($value, ['default', 'preserve'], true) => [self::NS_XML, 'xml:space', $value],
                $name === 'lang' && preg_match('/^[A-Za-z0-9\-]{1,35}$/D', $value) === 1 => [self::NS_XML, 'xml:lang', $value],
                // xml:base valt hier af: dat verlegt waar elke relatieve verwijzing naartoe wijst.
                default => null,
            };
        }

        // href en xlink:href zijn in fill() al samen behandeld; verder bestaat er
        // geen attribuut met een namespace.
        if (($namespace !== null && $namespace !== '') || $name === 'href') {
            return null;
        }

        if (isset(self::CSS_PROPERTIES[$name]) || isset(self::TRANSFORM_ATTRIBUTES[$name])) {
            if ($inlineRoot && isset(self::INLINE_ROOT_BLOCKED[$name])) {
                return null;
            }

            $clean = $this->cleanCssValue($value, $prefix);

            return $clean === null || ! $this->propertyIsBounded($name, $clean) ? null : [null, $name, $clean];
        }

        $isAnimation = isset(self::ANIMATION_ELEMENTS[$element]);

        if (! isset(self::ATTRIBUTES[$name]) && ! ($isAnimation && isset(self::ANIMATION_ATTRIBUTES[$name]))) {
            return null;
        }

        $clean = match ($name) {
            'id' => preg_match('/^'.self::ID.'$/D', $value) === 1 ? $prefix.$value : null,
            'aria-labelledby', 'aria-describedby' => $this->cleanIdList($value, $prefix),
            // Inline zou een class de CSS van de site op de SVG loslaten (fixed, inset-0);
            // de eigen stylesheet waar de class bij hoorde is daar al weg.
            'class' => $profile === self::PROFILE_INLINE || preg_match('/^[A-Za-z0-9_\- ]{1,500}$/D', $value) !== 1 ? null : $value,
            'style' => $this->cleanDeclarations($value, $prefix, $inlineRoot ? self::INLINE_ROOT_BLOCKED : []),
            // Vrije tekst voor een schermlezer; de serialisatie escapet hem.
            'aria-label' => mb_substr(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value) ?? '', 0, self::MAX_TEXT_LENGTH),
            default => $this->cleanPlainValue($value),
        };

        if ($clean === null || $clean === '') {
            return null;
        }

        if ($isRoot && ($name === 'width' || $name === 'height') && ! $this->rootLengthIsBounded($clean)) {
            return null;
        }

        return $this->filterNumberIsBounded($element, $name, $clean, $attribute) ? [null, $name, $clean] : null;
    }

    /**
     * De getallen waar een filter op rekent. Een waarde boven de grens valt weg,
     * waarna de standaardwaarde geldt (geen vervaging, een octaaf, het standaard
     * filtergebied); dat is altijd de goedkoopste uitkomst.
     */
    private function filterNumberIsBounded(string $element, string $name, string $value, DOMAttr $attribute): bool
    {
        switch ($name) {
            case 'numOctaves':
                return $this->numbersAreWithin($value, 0, self::MAX_OCTAVES, 1);
            case 'stdDeviation':
                return $this->numbersAreWithin($value, 0, self::MAX_BLUR, 2);
            case 'radius':
                return $this->numbersAreWithin($value, 0, self::MAX_MORPHOLOGY_RADIUS, 2);
            case 'order':
                return $this->numbersAreWithin($value, 1, self::MAX_CONVOLUTION_ORDER, 2);
        }

        $isFilter = $element === 'filter';

        if (! in_array($name, ['x', 'y', 'width', 'height'], true) || (! $isFilter && ! str_starts_with($element, 'fe'))) {
            return true;
        }

        if (preg_match('/^\s*([+-]?(?:\d{1,7}(?:\.\d{1,10})?|\.\d{1,10}))(%|px)?\s*$/D', $value, $match) !== 1) {
            return false;
        }

        $number = (float) $match[1];

        if (($match[2] ?? '') === '%') {
            return $number >= -100 && $number <= 300;
        }

        // Zonder filterUnits="userSpaceOnUse" is een kaal getal een fractie van het
        // getekende element: 200 betekent daar 20.000 procent.
        $userSpace = ! $isFilter || $attribute->ownerElement?->getAttribute('filterUnits') === 'userSpaceOnUse';

        return $userSpace ? abs($number) <= self::MAX_DIMENSION : $number >= -1 && $number <= 3;
    }

    private function numbersAreWithin(string $value, float $minimum, float $maximum, int $maximumCount): bool
    {
        $numbers = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        if ($numbers === [] || count($numbers) > $maximumCount) {
            return false;
        }

        foreach ($numbers as $number) {
            // Geen exponent: 1e9 is een geldig getal en past in een paar tekens.
            if (preg_match('/^[+-]?(?:\d{1,7}(?:\.\d{1,10})?|\.\d{1,10})$/D', $number) !== 1
                || (float) $number < $minimum || (float) $number > $maximum) {
                return false;
            }
        }

        return true;
    }

    /** Geldt voor een eigenschap als attribuut, in een style-attribuut en in een stylesheet. */
    private function propertyIsBounded(string $property, string $value): bool
    {
        if ($property !== 'stroke-dasharray' || strcasecmp(trim($value), 'none') === 0) {
            return true;
        }

        // Een streepje van een duizendste eenheid op een lange lijn is miljoenen
        // lijnstukjes voor een renderer.
        $dashes = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        if ($dashes === [] || count($dashes) > self::MAX_DASHES) {
            return false;
        }

        foreach ($dashes as $dash) {
            if (preg_match('/^(\d{1,7}(?:\.\d{1,10})?|\.\d{1,10})(?:px|%)?$/D', $dash, $match) !== 1 || (float) $match[1] < self::MIN_DASH) {
                return false;
            }
        }

        return true;
    }

    private function cleanIdList(string $value, string $prefix): ?string
    {
        // Eerst de lengte: een lijst van 1,9 MB aan id's kostte bij het
        // splitsen 100 MB geheugen, genoeg om een werker te laten crashen in
        // plaats van het bestand netjes te weigeren.
        if (strlen($value) > self::MAX_CSS_VALUE_LENGTH) {
            return null;
        }

        $ids = [];

        foreach (preg_split('/\s+/', trim($value)) as $id) {
            if (preg_match('/^'.self::ID.'$/D', $id) === 1) {
                $ids[] = $prefix.$id;
            }
        }

        return $ids === [] || count($ids) > 20 ? null : implode(' ', $ids);
    }

    /**
     * Getallen, opsommingen en padgegevens. Zonder aanhalingstekens, backslash
     * of een dubbele punt buiten een kloktijd past hier geen URL en geen CSS in.
     */
    private function cleanPlainValue(string $value): ?string
    {
        $withoutClock = preg_replace('/(?<=\d):(?=\d)/', '', $value);

        if ($withoutClock === null || preg_match('/^[A-Za-z0-9\s#%.,()\-+\/;_]*$/D', $withoutClock) !== 1) {
            return null;
        }

        if (preg_match('/url\s*\(/i', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * Twee vormen en verder niets: een verwijzing binnen het document, of een
     * ingesloten rasterafbeelding. De XML-parser heeft entiteiten al omgezet,
     * dus java&#x09;script: is hier al java<tab>script: en past in geen van beide.
     */
    private function cleanHref(string $element, string $value, string $prefix): ?string
    {
        $trimmed = trim($value, " \t\n\r\x0B\x0C");

        if (preg_match('/^#('.self::ID.')$/D', $trimmed, $match) === 1) {
            return isset(self::FRAGMENT_HREF_ELEMENTS[$element]) ? '#'.$prefix.$match[1] : null;
        }

        if (! isset(self::DATA_HREF_ELEMENTS[$element])) {
            return null;
        }

        // Exporttools breken base64 af op regels; een browser negeert die witruimte ook.
        $compact = preg_replace('/[\x09\x0A\x0C\x0D\x20]+/', '', $value);

        if ($compact === null || preg_match('~^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/]+={0,2})$~Di', $compact, $match) !== 1) {
            return null;
        }

        $type = strtolower($match[1]);
        $bytes = base64_decode($match[2], true);

        if ($bytes === false) {
            return null;
        }

        // Het opgegeven type is een bewering van de uploader, en de afmetingen in de kop
        // bepalen hoeveel geheugen het decoderen kost: een png van een paar kilobyte kan
        // zeggen dat hij 60.000 bij 60.000 is. Kop niet te lezen: geen afbeelding.
        $size = $this->rasterSize($type, $bytes);

        if ($size === null || $size[0] < 1 || $size[1] < 1
            || $size[0] > self::MAX_RASTER_DIMENSION || $size[1] > self::MAX_RASTER_DIMENSION
            || $size[0] * $size[1] > self::MAX_RASTER_AREA) {
            return null;
        }

        return 'data:image/'.$type.';base64,'.$match[2];
    }

    /**
     * @return array{0: int, 1: int}|null breedte en hoogte uit de kop, null als de bytes niet dat formaat zijn
     */
    private function rasterSize(string $type, string $bytes): ?array
    {
        $length = strlen($bytes);

        if ($type === 'png') {
            if ($length < 24 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || substr($bytes, 12, 4) !== 'IHDR') {
                return null;
            }

            $size = unpack('Nwidth/Nheight', $bytes, 16);

            return [$size['width'], $size['height']];
        }

        if ($type === 'gif') {
            return $this->gifSize($bytes, $length);
        }

        if ($type === 'jpeg') {
            return $this->jpegSize($bytes, $length);
        }

        if ($length < 30 || ! str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            return null;
        }

        $chunk = substr($bytes, 12, 4);

        if ($chunk === 'VP8 ' && substr($bytes, 23, 3) === "\x9D\x01\x2A") {
            $size = unpack('vwidth/vheight', $bytes, 26);

            return [$size['width'] & 0x3FFF, $size['height'] & 0x3FFF];
        }

        if ($chunk === 'VP8L' && $bytes[20] === "\x2F") {
            $bits = unpack('V', $bytes, 21)[1];

            return [($bits & 0x3FFF) + 1, (($bits >> 14) & 0x3FFF) + 1];
        }

        if ($chunk === 'VP8X') {
            return [
                unpack('V', substr($bytes, 24, 3)."\0")[1] + 1,
                unpack('V', substr($bytes, 27, 3)."\0")[1] + 1,
            ];
        }

        return null;
    }

    /**
     * Een gif heeft een logisch scherm en daarnaast per frame een eigen maat, en
     * een decoder reserveert voor allebei. Daarom worden alle blokken afgelopen
     * en telt de grootste maat die ergens genoemd wordt.
     */
    private function gifSize(string $bytes, int $length): ?array
    {
        if ($length < 13 || (! str_starts_with($bytes, 'GIF87a') && ! str_starts_with($bytes, 'GIF89a'))) {
            return null;
        }

        $screen = unpack('vwidth/vheight', $bytes, 6);
        $width = $screen['width'];
        $height = $screen['height'];
        $flags = ord($bytes[10]);
        $offset = 13 + (($flags & 0x80) !== 0 ? 3 * (2 << ($flags & 0x07)) : 0);
        $frames = 0;

        while ($offset < $length) {
            $block = $bytes[$offset];

            if ($block === ';') {
                break;
            }

            if ($block === '!') {
                $offset += 2;
            } elseif ($block === ',') {
                if ($offset + 10 > $length || ++$frames > self::MAX_GIF_FRAMES) {
                    return null;
                }

                $frame = unpack('vleft/vtop/vwidth/vheight', $bytes, $offset + 1);
                $width = max($width, $frame['left'] + $frame['width']);
                $height = max($height, $frame['top'] + $frame['height']);
                $frameFlags = ord($bytes[$offset + 9]);
                // Beschrijving, eventuele lokale kleurentabel en de minimale codegrootte.
                $offset += 10 + (($frameFlags & 0x80) !== 0 ? 3 * (2 << ($frameFlags & 0x07)) : 0) + 1;
            } else {
                return null;
            }

            // Subblokken: een lengtebyte en zoveel bytes, tot een lengte van nul.
            while (true) {
                if ($offset >= $length) {
                    return null;
                }

                $size = ord($bytes[$offset]);
                $offset += 1 + $size;

                if ($size === 0) {
                    break;
                }
            }
        }

        return [$width, $height];
    }

    private function jpegSize(string $bytes, int $length): ?array
    {
        if (! str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return null;
        }

        $offset = 2;

        for ($guard = 0; $guard < 2000 && $offset + 4 <= $length; $guard++) {
            if ($bytes[$offset] !== "\xFF") {
                return null;
            }

            $marker = ord($bytes[$offset + 1]);

            if ($marker === 0xFF) {
                $offset++;

                continue;
            }

            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $offset += 2;

                continue;
            }

            // Begin van de beelddata of einde van het bestand zonder dat er een maat stond.
            if ($marker === 0xD9 || $marker === 0xDA) {
                return null;
            }

            $segment = unpack('n', $bytes, $offset + 2)[1];

            if ($segment < 2) {
                return null;
            }

            if ($marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                if ($offset + 9 > $length) {
                    return null;
                }

                $size = unpack('nheight/nwidth', $bytes, $offset + 5);

                return [$size['width'], $size['height']];
            }

            $offset += 2 + $segment;
        }

        return null;
    }

    private function animationIsSafe(DOMElement $source): bool
    {
        $name = $source->localName;
        $attributeName = null;
        $duration = null;
        $repeats = false;

        foreach ($source->attributes as $attribute) {
            $local = $attribute->localName;
            $hasNamespace = $attribute->namespaceURI !== null && $attribute->namespaceURI !== '';

            if (strncasecmp($local, 'on', 2) === 0) {
                return false;
            }

            if ($local === 'href' && (! $hasNamespace || $attribute->namespaceURI === self::NS_XLINK)) {
                // Het doel van de animatie moet binnen het document liggen.
                if ($this->cleanHref($name, $attribute->value, '') === null) {
                    return false;
                }

                continue;
            }

            if ($hasNamespace) {
                continue;
            }

            if ($local === 'attributeName') {
                $attributeName = $attribute->value;
            } elseif ($local === 'dur') {
                $duration = trim($attribute->value);
            } elseif ($local === 'repeatCount' || $local === 'repeatDur') {
                $repeats = true;
            }

            // De waarden die de animatie zet worden pas later door de browser toegepast;
            // zit er iets in dat niet door de keuring komt, dan gaat de hele animatie weg.
            if (in_array($local, ['from', 'to', 'by', 'values'], true) && $this->cleanPlainValue($attribute->value) === null) {
                return false;
            }
        }

        if ($duration !== null && $duration !== 'indefinite') {
            $seconds = $this->durationInSeconds($duration);

            // Een herhaling van een paar milliseconden laat de renderer onafgebroken
            // opnieuw tekenen. Een duur die niet te lezen is geldt als te kort.
            if ($seconds === null || ($repeats && $seconds < self::MIN_REPEATING_DURATION)) {
                return false;
            }
        }

        if ($name === 'mpath') {
            return true;
        }

        if ($name === 'animateMotion') {
            return $attributeName === null;
        }

        if ($name === 'animateTransform') {
            return isset(self::TRANSFORM_ATTRIBUTES[$attributeName ?? '']) || $attributeName === 'transform';
        }

        // Exact, zonder trimmen of kleine letters: wat niet letterlijk op de lijst staat is twijfel.
        return $attributeName !== null && isset(self::ANIMATABLE[$attributeName]);
    }

    private function durationInSeconds(string $value): ?float
    {
        if (preg_match('/^(\d{1,9}(?:\.\d{1,9})?|\.\d{1,9})(h|min|s|ms)?$/D', $value, $match) === 1) {
            return (float) $match[1] * ['' => 1, 's' => 1, 'ms' => 0.001, 'min' => 60, 'h' => 3600][$match[2] ?? ''];
        }

        if (preg_match('/^(?:(\d{1,4}):)?(\d{1,2}):(\d{1,2}(?:\.\d{1,9})?)$/D', $value, $match) === 1) {
            return (int) $match[1] * 3600 + (int) $match[2] * 60 + (float) $match[3];
        }

        return null;
    }

    private function rebuildStyle(DOMElement $source, DOMDocument $target): ?DOMElement
    {
        if ($source->hasAttribute('type') && strtolower(trim($source->getAttribute('type'))) !== 'text/css') {
            return null;
        }

        foreach ($source->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return null;
            }
        }

        $css = $this->cleanStylesheet($source->textContent);

        if ($css === null) {
            return null;
        }

        $element = $target->createElementNS(self::NS_SVG, 'style');
        $element->appendChild($target->createTextNode($css));

        return $element;
    }

    /**
     * Alleen de platte vorm "selector { declaraties }". Zodra er iets in staat
     * dat daar niet in past (een @-regel, een escape, geneste accolades) gaat het
     * hele style-element weg: dan is niet met zekerheid te zeggen wat een browser
     * ervan maakt.
     */
    private function cleanStylesheet(string $css): ?string
    {
        $css = $this->stripCssComments($css);

        if ($css === null || preg_match('/[\\\\@<]/', $css) === 1) {
            return null;
        }

        $rules = [];
        $offset = 0;

        while (preg_match('/\G\s*([^{}]+)\{([^{}]*)\}/', $css, $match, 0, $offset) === 1) {
            $offset += strlen($match[0]);
            $selector = trim(preg_replace('/\s+/', ' ', $match[1]) ?? '');
            $declarations = $this->cleanDeclarations($match[2], '');

            // Geen attribuutselectors: die hebben aanhalingstekens en vrije tekst nodig.
            if ($declarations !== null && preg_match('/^[A-Za-z0-9_\- .#,>+~*:()]{1,500}$/D', $selector) === 1) {
                $rules[] = $selector.'{'.$declarations.'}';
            }
        }

        if (trim(substr($css, $offset)) !== '' || $rules === []) {
            return null;
        }

        // Zonder scheidingsteken: de uitvoer mag niet groter worden dan de invoer was.
        return implode('', $rules);
    }

    /**
     * @param  array<string, bool>  $blocked  eigenschappen die op deze plek niet mogen
     */
    private function cleanDeclarations(string $css, string $prefix, array $blocked = []): ?string
    {
        $css = $this->stripCssComments($css);

        if ($css === null) {
            return null;
        }

        $declarations = [];

        // Naief splitsen is hier goed genoeg, juist omdat de uitvoer opnieuw wordt
        // opgebouwd: elk stuk moet op zichzelf door de keuring, dus een puntkomma in
        // een string levert twee stukken op die allebei afvallen.
        foreach (explode(';', $css) as $declaration) {
            $parts = explode(':', $declaration, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));

            if (! isset(self::CSS_PROPERTIES[$property]) || isset($blocked[$property])) {
                continue;
            }

            $value = $this->cleanCssValue($parts[1], $prefix);

            if ($value !== null && $this->propertyIsBounded($property, $value)) {
                $declarations[] = $property.':'.$value;
            }
        }

        return $declarations === [] ? null : implode(';', $declarations);
    }

    /**
     * Commentaar wordt een spatie, zoals in CSS zelf: u, commentaar, rl( is daar
     * geen url( en hier dus ook niet. Een onafgesloten commentaar is niet te
     * beoordelen. Met strpos en niet met een regex: die begon bij elke opening
     * opnieuw tot het einde te zoeken.
     */
    private function stripCssComments(string $css): ?string
    {
        $stripped = '';
        $position = 0;

        while (($start = strpos($css, '/*', $position)) !== false) {
            $end = strpos($css, '*/', $start + 2);

            if ($end === false) {
                return null;
            }

            $stripped .= substr($css, $position, $start - $position).' ';
            $position = $end + 2;
        }

        $stripped .= substr($css, $position);

        return str_contains($stripped, '/*') || str_contains($stripped, '*/') ? null : $stripped;
    }

    /**
     * Keurt één CSS-waarde en schrijft hem opnieuw. De toegestane tekens bevatten
     * geen backslash (geen escapes), geen dubbele punt (geen schema's) en geen
     * apenstaartje, puntkomma of accolade (geen @import, geen uitbraak). Wat dan
     * nog iets kan ophalen is een functie, en daar is een eigen lijst voor.
     */
    private function cleanCssValue(string $value, string $prefix): ?string
    {
        if (strlen($value) > self::MAX_CSS_VALUE_LENGTH) {
            return null;
        }

        $value = $this->stripCssComments($value);

        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if ($value === '' || preg_match('/^[A-Za-z0-9 #%.,()\-+\/\'"!_]+$/D', $value) !== 1) {
            return null;
        }

        // inherit maakt van een niet-geërfde eigenschap (mask, filter, clip-path) een
        // geërfde, en dan klopt de telling van verwijzingen niet meer. Voor een eigenschap
        // die al erft is weglaten hetzelfde als inherit.
        if (preg_match('/^inherit(?![A-Za-z0-9_\-])/i', $value) === 1) {
            return null;
        }

        // De enige toegestane url(): een verwijzing binnen het document. Die gaat er
        // eerst uit, zodat elke url( die daarna nog staat per definitie fout is.
        $references = [];
        $work = preg_replace_callback(
            '/url\( ?([\'"]?)#('.self::ID.')\1 ?\)/i',
            function (array $match) use (&$references): string {
                $references[] = $match[2];

                return '<'.(count($references) - 1).'>';
            },
            $value,
        );

        if ($work === null) {
            return null;
        }

        // Strings alleen als naam (een lettertype): letters, cijfers, spatie, streepje.
        $bare = preg_replace('/([\'"])[A-Za-z0-9 _\-]*\1/', ' ', $work);

        if ($bare === null || str_contains($bare, "'") || str_contains($bare, '"')) {
            return null;
        }

        if (! $this->functionsAreAllowed($bare)) {
            return null;
        }

        if (str_contains($bare, '!') && preg_match('/^[^!]* ?! ?important$/Di', $bare) !== 1) {
            return null;
        }

        // Kan na het bovenstaande niets meer doen, maar hoort hier nooit te staan.
        if (preg_match('/expression|javascript/i', $bare) === 1) {
            return null;
        }

        return preg_replace_callback(
            '/<(\d+)>/',
            fn (array $match): string => 'url(#'.$prefix.$references[(int) $match[1]].')',
            $work,
        );
    }

    /**
     * Eén gang over de waarde. De naam voor een haakje wordt bijgehouden terwijl
     * de tekens voorbijkomen, zodat er per haakje niet opnieuw over de voorgaande
     * tekst gezocht wordt.
     */
    private function functionsAreAllowed(string $value): bool
    {
        $depth = 0;
        $nameStart = -1;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $character = $value[$i];

            if ($character === '(') {
                // De naam moet direct voor het haakje staan en op de lijst: url(, image-set(,
                // expression( en elke functie die nog bedacht wordt vallen hier af.
                if (++$depth > self::MAX_CSS_NESTING || $nameStart < 0
                    || ! isset(self::CSS_FUNCTIONS[strtolower(substr($value, $nameStart, $i - $nameStart))])) {
                    return false;
                }

                $nameStart = -1;
            } elseif ($character === ')') {
                if (--$depth < 0) {
                    return false;
                }

                $nameStart = -1;
            } elseif ($character === '-' || $character === '_' || ctype_alnum($character)) {
                $nameStart = $nameStart < 0 ? $i : $nameStart;
            } else {
                $nameStart = -1;
            }
        }

        return $depth === 0;
    }

    /**
     * Begrenst wat een renderer moet tekenen. Elke verwijzing naar een id is een
     * kant in een graaf: href op een element dat zijn doel tekent of overneemt, en
     * url(#id) in elk attribuut, elk style-attribuut en elke stylesheetregel.
     * Geweigerd wordt een dubbel id (welke wint verschilt per renderer), een
     * kring, een te lange keten en een te groot uitgeschreven gewicht.
     */
    private function expansionIsBounded(DOMElement $root): bool
    {
        $this->targets = [];
        $this->measured = new SplObjectStorage();
        $this->visiting = new SplObjectStorage();

        try {
            foreach ($root->ownerDocument->getElementsByTagName('*') as $element) {
                $id = $element->getAttribute('id');

                if ($id === '') {
                    continue;
                }

                if (isset($this->targets[$id])) {
                    return false;
                }

                $this->targets[$id] = $element;
            }

            return $this->indexStylesheets($root) && $this->measure($root, 0) !== null;
        } finally {
            $this->targets = [];
            $this->styleIndex = ['id' => [], 'class' => [], 'tag' => [], 'any' => []];
            $this->measured = null;
            $this->visiting = null;
        }
    }

    /**
     * Welke stylesheetregels met een verwijzing op welk element kunnen slaan. Van
     * een selector telt alleen het laatste deel, en alleen tag, id en classes
     * daarvan; alles wat verder beperkt wordt genegeerd. Zo matcht een regel hier
     * hooguit op meer elementen dan in een browser, nooit op minder.
     */
    private function indexStylesheets(DOMElement $root): bool
    {
        $this->styleIndex = ['id' => [], 'class' => [], 'tag' => [], 'any' => []];
        $count = 0;

        foreach ($root->ownerDocument->getElementsByTagName('style') as $style) {
            $css = $style->textContent;

            if (! str_contains($css, 'url(#')) {
                continue;
            }

            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

            foreach ($rules as $rule) {
                if (! str_contains($rule[2], 'url(#')) {
                    continue;
                }

                $references = [[], [], [], []];
                $this->collectDeclarationReferences($rule[2], $references);

                foreach (explode(',', $rule[1]) as $selector) {
                    if (++$count > self::MAX_STYLE_REFERENCES) {
                        return false;
                    }

                    $tag = '';
                    $id = null;
                    $classes = [];

                    // Een pseudo-klasse of haakje kan de betekenis omkeren (:not): dan geldt de regel voor alles.
                    if (! str_contains($selector, ':') && ! str_contains($selector, '(')) {
                        $parts = preg_split('/[\s>+~]+/', trim($selector), -1, PREG_SPLIT_NO_EMPTY);
                        preg_match('/^([A-Za-z][A-Za-z0-9\-]*)?((?:[.#][A-Za-z0-9_\-]+)*)/', $parts === [] ? '' : end($parts), $subject);
                        $tag = strtolower($subject[1] ?? '');
                        preg_match_all('/([.#])([A-Za-z0-9_\-]+)/', $subject[2] ?? '', $tokens, PREG_SET_ORDER);

                        foreach ($tokens as $token) {
                            if ($token[1] === '#') {
                                $id = $token[2];
                            } else {
                                $classes[] = $token[2];
                            }
                        }
                    }

                    $entry = [$tag, $id, $classes, $references];

                    if ($id !== null) {
                        $this->styleIndex['id'][$id][] = $entry;
                    } elseif ($classes !== []) {
                        $this->styleIndex['class'][$classes[0]][] = $entry;
                    } elseif ($tag !== '') {
                        $this->styleIndex['tag'][$tag][] = $entry;
                    } else {
                        $this->styleIndex['any'][] = $entry;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param  array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>}  $references
     */
    private function collectDeclarationReferences(string $css, array &$references): void
    {
        foreach (explode(';', $css) as $declaration) {
            $parts = explode(':', $declaration, 2);

            if (count($parts) === 2) {
                $this->collectValueReferences(trim($parts[0]), $parts[1], $references);
            }
        }
    }

    /**
     * Soorten: 0 structureel (href), 1 een keer toegepast op het element als geheel
     * (mask, clip-path, filter), 2 verf die elk getekend element eronder opnieuw
     * gebruikt (fill, stroke), 3 marker, een keer per hoekpunt.
     *
     * @param  array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>}  $references
     */
    private function collectValueReferences(string $property, string $value, array &$references): void
    {
        if (preg_match_all('/url\(#('.self::ID.')\)/', $value, $matches) === 0) {
            return;
        }

        $kind = match (true) {
            str_starts_with($property, 'marker') => 3,
            $property === 'fill' || $property === 'stroke' => 2,
            default => 1,
        };

        foreach ($matches[1] as $id) {
            $references[$kind][] = $id;
        }
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>}
     */
    private function referencesOf(DOMElement $element): array
    {
        $references = [[], [], [], []];
        $name = $element->localName;
        $id = '';
        $classes = [];

        foreach ($element->attributes as $attribute) {
            $attributeName = $attribute->localName;
            $value = $attribute->value;

            if ($attributeName === 'href') {
                // Een link en het doel van een animatie tekenen hun doel niet.
                if (! isset(self::NAVIGATION_ELEMENTS[$name]) && str_starts_with($value, '#')) {
                    $references[0][] = substr($value, 1);
                }
            } elseif ($attributeName === 'id') {
                $id = $value;
            } elseif ($attributeName === 'class') {
                $classes = preg_split('/ +/', $value, -1, PREG_SPLIT_NO_EMPTY);
            } elseif (! str_contains($value, 'url(#')) {
                continue;
            } elseif ($attributeName === 'style') {
                $this->collectDeclarationReferences($value, $references);
            } else {
                $this->collectValueReferences($attributeName, $value, $references);
            }
        }

        $tag = strtolower($name);
        $candidates = [$this->styleIndex['any'], $this->styleIndex['tag'][$tag] ?? [], $this->styleIndex['id'][$id] ?? []];

        foreach ($classes as $class) {
            $candidates[] = $this->styleIndex['class'][$class] ?? [];
        }

        foreach ($candidates as $entries) {
            foreach ($entries as [$entryTag, $entryId, $entryClasses, $entryReferences]) {
                if (($entryTag !== '' && $entryTag !== $tag) || ($entryId !== null && $entryId !== $id)
                    || array_diff($entryClasses, $classes) !== []) {
                    continue;
                }

                foreach ($entryReferences as $kind => $ids) {
                    array_push($references[$kind], ...$ids);
                }
            }
        }

        return $references;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}|null uitgeschreven gewicht, aantal getekende elementen,
     *                                                    aantal hoekpunten en diepte aan sprongen onder dit element; null bij een overschrijding
     */
    private function measure(DOMElement $element, int $hops): ?array
    {
        if (isset($this->visiting[$element])) {
            return null;
        }

        if (isset($this->measured[$element])) {
            $known = $this->measured[$element];

            return $hops + $known[3] > self::MAX_REFERENCE_DEPTH ? null : $known;
        }

        $this->visiting[$element] = true;

        $name = $element->localName;
        $shape = strlen($element->getAttribute('d')) + strlen($element->getAttribute('points'));
        // Een pad telt naar zijn inhoud: een megabyte aan segmenten is geen enkel element.
        $weight = 1 + intdiv($shape, 1000);
        $count = 1;
        $vertices = match ($name) {
            'path', 'polyline', 'polygon' => 1 + intdiv($shape, 2),
            'line' => 2,
            default => 0,
        };
        $depth = 0;

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $below = $this->measure($child, $hops);

            if ($below === null) {
                return null;
            }

            $weight += $below[0];
            $count += $below[1];
            $vertices += $below[2];
            $depth = max($depth, $below[3]);
        }

        [$structural, $applied, $paints, $markers] = $this->referencesOf($element);

        foreach ([$structural, $applied, $paints, $markers] as $kind => $ids) {
            foreach ($ids as $id) {
                $target = $this->targets[$id] ?? null;

                if ($target === null) {
                    continue;
                }

                if ($hops + 1 > self::MAX_REFERENCE_DEPTH) {
                    return null;
                }

                $below = $this->measure($target, $hops + 1);

                if ($below === null) {
                    return null;
                }

                $depth = max($depth, $below[3] + 1);

                if ($kind === 0) {
                    $weight += $below[0];
                    $count += $below[1];
                    $vertices += $below[2];
                } elseif ($kind === 1) {
                    $weight += $below[0];
                } elseif ($target->localName === 'linearGradient' || $target->localName === 'radialGradient') {
                    // Een verloop tekent geen elementen; het kost per gebruik een vast bedrag.
                    $weight += 1;
                } else {
                    // fill, stroke en marker erven: elk element onder dit element gebruikt
                    // het doel opnieuw. De inhoud van het doel erft op zijn beurt van zijn
                    // eigen voorouders, dus een doel binnen dit element verwijst naar zichzelf.
                    for ($ancestor = $target->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
                        if ($ancestor === $element) {
                            return null;
                        }
                    }

                    $weight += ($kind === 2 ? $count : max(1, $vertices)) * $below[0];
                }

                if ($weight > self::MAX_EXPANDED_ELEMENTS) {
                    return null;
                }
            }
        }

        unset($this->visiting[$element]);

        if ($weight > self::MAX_EXPANDED_ELEMENTS || $hops + $depth > self::MAX_REFERENCE_DEPTH) {
            return null;
        }

        $known = [$weight, $count, $vertices, $depth];
        $this->measured[$element] = $known;

        return $known;
    }
}
