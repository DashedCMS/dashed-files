<?php

/**
 * Instellingen van de inname van bestanden in de mediabibliotheek. Alles hier
 * is optioneel: wat ontbreekt valt terug op de standaard in
 * Dashed\DashedFiles\Classes\Ingest\IngestPolicy. De sleutel per bron is het
 * deel vóór de dubbele punt (`stock` voor `stock:pexels`). Bronnen: upload,
 * system, api, chat, url, ai, stock.
 *
 * De externe bronnen in het keuzevenster (URL, stock, AI) hebben hier geen
 * instellingen: sleutels en schakelaars staan per site bij Instellingen,
 * Media. Bewust geen gedeelde sleutel in .env: de voorwaarden van Pexels en
 * Pixabay zeggen niets over één sleutel voor meerdere klantsites.
 */
return [
    // Dwingt de motor van de mediabibliotheek af, boven de instelling in de
    // database: `legacy` (de pagina van het pakket) of `dashed` (de eigen
    // pagina). Leeg laten volgt de instelling (dashed:media-engine), en
    // zonder instelling draait de eigen bibliotheek (`dashed`).
    'media_engine' => env('DASHED_MEDIA_ENGINE'),

    'ingest' => [
        // Toegestane bestandstypen per bron, als lijst mimetypes. Leeg laten
        // gebruikt de standaardlijst. HTML, scripts en uitvoerbare typen zijn
        // nooit toegestaan, ook niet als ze hier staan.
        'allowed' => [
            // 'system' => ['image/jpeg', 'image/png', 'application/pdf'],
        ],

        // Grootste download per bron, in bytes. Standaard 100 MB voor
        // `system` (adressen die code kiest, zoals een import) en 25 MB voor
        // de rest.
        'max_download_bytes' => [],

        // Poorten waarop `system` mag downloaden. Andere bronnen: alleen 443.
        'allowed_ports' => [
            'system' => [80, 443],
        ],

        // Hostnamen waarvoor `system` de toets op interne adressen overslaat,
        // bijvoorbeeld een beeldserver op het eigen netwerk. Elke host hier is
        // een adres waar een import alles van mag ophalen: houd de lijst leeg
        // tenzij een import het echt nodig heeft.
        'trusted_hosts' => [],

        // Grootste afbeelding per bron, in megapixels. Een klein bestand met
        // enorme afmetingen kost de beeldbewerking gigabytes aan geheugen.
        'max_megapixels' => [
            'chat' => 50,
            'api' => 50,
            // Een AI-upscale van een gewone foto van 24 megapixel levert al
            // 96 megapixel op, en die aanroep is dan al betaald. `ai` blijft
            // verder een onvertrouwde bron (geen extern programma, geen svg,
            // afmetingen verplicht leesbaar); alleen de grens is die van een upload.
            'ai' => 150,
        ],

        // Het grootste bestand dat de wachtrijtaak een vingerafdruk geeft, in
        // MB. Eén bestand lezen is niet te onderbreken en de taak moet binnen
        // de standaard retry_after van 90 seconden klaar zijn. Grotere
        // bestanden slaat de taak over; de doctor telt ze en
        // `dashed:media-checksums --now` doet ze buiten de wachtrij. 0 = geen grens.
        'queue_hash_max_mb' => (int) env('DASHED_FILES_QUEUE_HASH_MAX_MB', 250),

        // Wachtrij voor het berekenen van vingerafdrukken van bestaande
        // bestanden. null is de standaardwachtrij; een drukke webshop zet
        // hier een eigen wachtrij met een eigen werker.
        'queue' => env('DASHED_FILES_INGEST_QUEUE'),
    ],

    // De `Cache-Control` die een NIEUW mediabestand op een objectopslag krijgt.
    // Een bestaand object houdt zijn header tot het opnieuw wordt geschreven.
    // Immutable alleen voor een origineel: dat pad krijgt nooit andere bytes
    // (een vervanging of bewerking maakt een nieuwe map). Een formaat, een
    // responsive beeld en een svg kunnen op hetzelfde pad opnieuw geschreven
    // worden en krijgen een kortere termijn met must-revalidate. Een sleutel
    // met een lege waarde zet dat onderdeel uit: dan geldt
    // `media-library.remote.extra_headers`.
    'cache' => [
        'original' => 'public, max-age=31536000, immutable',
        'conversion' => 'public, max-age=604800, must-revalidate',
    ],

    // Laadt de media van een pagina in één cacheronde voor, zodra de
    // content-blocks-component rendert. Zet uit om terug te vallen op één
    // cacheaanvraag per afbeelding; de uitgeleverde adressen veranderen niet.
    'preload_content_media' => true,

    // Welke formaten er worden voorgeladen. Elk formaat is één cacheronde
    // en, voor wat nog niet in de cache staat, één query.
    'preload_conversions' => ['medium'],

    'conversions' => [
        // Dwingt AVIF aan of uit, boven de instelling `media_avif_enabled`
        // in de database (standaard uit). Leeg laten volgt de instelling.
        // AVIF wordt alleen via de pomp gemaakt (dashed:media-conversions
        // avif), nooit bij een upload of op het pad van een bezoeker.
        'avif' => env('DASHED_MEDIA_AVIF'),

        // Na hoeveel dagen een opruimronde (dashed:media-conversions cleanup)
        // de uitsneden van een eerder focuspunt weghaalt. Browsers en het CDN bewaren een
        // afbeelding weken; korter dan dat levert dode adressen op.
        'cleanup_after_days' => 90,

        // Boven dit aantal megapixels begint de pomp niet aan een bestand:
        // één enorme foto met veel formaten haalt de time-out van de taak
        // niet en zou de gedeelde werker elke nacht opnieuw afschieten. Zo'n
        // bestand staat als overgeslagen in de run en in de doctor. 0 = geen grens.
        'max_megapixels' => 60,

        // Laat de beeldoptimalisatie van Spatie (cwebp, pngquant, ...) na het
        // maken van een formaat draaien. Standaard uit: het is veel trager en
        // levert voor webp grotere bestanden op. Alleen zetten als een server
        // met zijn eigen optimizers aantoonbaar kleinere bestanden geeft.
        'optimize' => env('DASHED_FILES_OPTIMIZE_CONVERSIONS', false),

        // Een eigen wachtrij voor AVIF-runs (AVIF coderen is traag). Leeg:
        // dezelfde wachtrij als de andere runs (`ingest.queue`).
        'avif_queue' => env('DASHED_FILES_AVIF_QUEUE'),
    ],

    'edits' => [
        // Grootste afbeelding die in het paneel bijgesneden, gedraaid of
        // gespiegeld kan worden, in megapixels. De bewerking draait in het
        // verzoek van de beheerder en laadt de hele afbeelding in het
        // geheugen. 0 = geen grens.
        'max_megapixels' => 25,
    ],

    'preview' => [
        // Boven deze grootte haalt de wachtrijtaak een video of pdf niet naar
        // de server om er een poster of miniatuur van te maken (de wachtrij
        // wordt gedeeld met ordermails). Van een mp4 worden duur en
        // afmetingen dan nog uit de kop gelezen als dat zonder het hele
        // bestand kan. Standaard 300 MB.
        'max_bytes' => (int) env('DASHED_FILES_PREVIEW_MAX_BYTES', 300 * 1024 * 1024),

        // De grootste poster die een browser bij het uploaden mag meesturen
        // (alleen als de server zelf geen ffmpeg heeft).
        'max_poster_bytes' => 2 * 1024 * 1024,
        'max_poster_megapixels' => 4,

        // Ghostscript maakt de miniatuur van een pdf. Standaard UIT, ook als
        // het programma op de server staat: het is een volledige
        // PostScript-vertolker die bestanden van gebruikers te lezen krijgt.
        // Zonder Ghostscript is er geen miniatuur, wel het aantal pagina's
        // waar PHP dat zelf kan lezen. Een bestand uit de chat of de API
        // gaat hoe dan ook nooit naar een extern programma.
        'ghostscript' => (bool) env('DASHED_FILES_GHOSTSCRIPT', false),

        // Het grootste beeld dat ffprobe en ffmpeg mogen decoderen, in
        // pixels (breedte maal hoogte). Daarboven komt er geen poster.
        'max_pixels' => (int) env('DASHED_FILES_PREVIEW_MAX_PIXELS', 50_000_000),

        // De tijd die één bestand in totaal krijgt (ophalen, bekijken en de
        // poster maken), in seconden. Elk extern programma krijgt hooguit
        // wat daarvan over is. Blijf onder de time-out van de taak (60).
        'deadline' => (int) env('DASHED_FILES_PREVIEW_DEADLINE', 40),

        // De pauze tussen twee bestanden van de achterstand
        // (dashed:media-previews), in seconden: de gedeelde wachtrij krijgt
        // zo lucht voor ordermails. 0 = geen pauze.
        'backlog_delay' => (int) env('DASHED_FILES_PREVIEW_BACKLOG_DELAY', 5),

        // Een vast pad per programma, voor een server waar het buiten het
        // zoekpad staat. Leeg laten zoekt het programma zelf.
        'binaries' => [
            'ffmpeg' => env('DASHED_FILES_FFMPEG'),
            'ffprobe' => env('DASHED_FILES_FFPROBE'),
            'gs' => env('DASHED_FILES_GHOSTSCRIPT_BINARY'),
        ],
    ],

    'private' => [
        // De schijf voor bestanden in een privé-map. Definieert de applicatie
        // deze schijf niet zelf in config/filesystems.php, dan leidt het
        // pakket hem af van `dashed`: dezelfde driver en gegevens,
        // zichtbaarheid privé, het voorvoegsel `private/`, en voor een
        // lokale schijf de map storage/app/private-media (buiten alles wat
        // de webserver uitlevert). Een eigen definitie moet zelf privé zijn
        // en mag niet naar dezelfde plek wijzen als `dashed`.
        'disk' => env('DASHED_FILES_PRIVATE_DISK', 'dashed-private'),

        // Hoe lang een ondertekende link naar een privé-bestand in het
        // beheer geldig is, in minuten.
        'link_minutes' => 15,

        // Na hoeveel mislukte pogingen de minuuttaak het verhuizen van een
        // item opgeeft. Het item blijft dan dicht, wordt één keer gemeld en
        // is opnieuw te proberen met `dashed:media-private relocate --force`
        // of de knop "Nu verder gaan".
        'max_attempts' => (int) env('DASHED_FILES_PRIVATE_MAX_ATTEMPTS', 5),

        // Hoe lang het slot op een item tijdens het verhuizen met
        // `dashed:media-private relocate --now` geldt, in seconden. De
        // wachtrijtaak gebruikt een vast slot van 120 s (zijn time-out van
        // 85 s plus marge).
        'lock_seconds' => 960,

        // Het grootste bestand dat de wachtrijtaak verhuist, in MB. Eén
        // bestand kopiëren is niet te onderbreken en de taak moet binnen de
        // standaard retry_after van 90 seconden klaar zijn. Een item met een
        // groter bestand blijft wachten (en dicht); de doctor meldt het en
        // `dashed:media-private relocate --now` verhuist het buiten de
        // wachtrij. 0 = geen grens (alleen met een ruime retry_after).
        'queue_max_mb' => (int) env('DASHED_FILES_PRIVATE_QUEUE_MAX_MB', 100),
    ],

    'trash' => [
        // Of de prullenbak van de mediabibliotheek zichzelf leegt na de
        // bewaartermijn `media_trash` (standaard 30 dagen). Standaard UIT:
        // wat in de prullenbak ligt blijft daar tot iemand het bewust
        // definitief verwijdert. De lijst "waar gebruikt" kent alleen de
        // aangemelde velden; een eigen veld van een project kan ontbreken, en
        // een bestand dat daar nog in staat zou anders stilletjes van de
        // schijf verdwijnen. Zet dit pas aan nadat `dashed:media-usages
        // --audit` niets meer meldt. Ook dan blijft staan wat nog een
        // verwijzing in de index heeft of sinds het weggooien nog door de
        // site is opgevraagd.
        'auto_purge' => (bool) env('DASHED_FILES_TRASH_AUTO_PURGE', false),

        // Na hoeveel dagen een item dat door code is weggegooid (een taak,
        // commando, de scheduler, tinker, een API-aanroep zonder
        // paneelsessie; `trashed_via = code`) definitief wordt opgeruimd,
        // ook als `auto_purge` uitstaat. Het oude pakket verwijderde zo'n
        // item meteen definitief; hier blijft het eerst een termijn staan.
        // Alleen als alle veiligheidsvoorwaarden gelden: de site vraagt het
        // niet meer op, geen verwijzing in de index, niet samengevoegd en
        // geen poster van een ander item. 0 zet dit uit.
        'code_deleted_purge_days' => (int) env('DASHED_FILES_TRASH_CODE_DELETED_PURGE_DAYS', 30),
    ],

    // Of de klassenamen van het oude mediabibliotheekpakket automatisch naar
    // MediaItem en MediaFolder wijzen zodra dat pakket weg is (zie
    // LegacyClassAliases). Zolang het pakket geïnstalleerd is gebeurt er
    // niets. Uitzetten is alleen zinvol om te vinden welke code de oude
    // namen nog noemt: die geeft dan een "class not found".
    'legacy_aliases' => (bool) env('DASHED_FILES_LEGACY_ALIASES', true),

    // De externe bronnen (URL, stock, AI): de remmen die niet per site in
    // het scherm staan. Sleutels en plafonds per site: Instellingen, Media.
    // Bewust geen sleutels hier.
    'source_limits' => [
        // Echte verzoeken naar een stockaanbieder per site per uur (een
        // treffer in de zoekcache telt niet). Ruim onder de limiet van de
        // aanbieder zelf, zodat een site nooit zijn sleutel laat blokkeren.
        'provider_hourly' => [
            'pexels' => 150,
            'pixabay' => 3000,
        ],

        // Imports van buiten (URL en stock) per gebruiker per uur, en hoeveel
        // er per gebruiker tegelijk mogen lopen.
        'imports_per_hour' => 60,
        'concurrent_imports' => 2,

        // De totale tijd voor het ophalen van een geplakt adres, over DNS,
        // alle doorverwijzingen en de body samen, in seconden.
        'url_deadline' => 20,

        // Na zoveel seconden zonder uitkomst geldt een AI-aanvraag als
        // mislukt; een taak die daarna nog wordt opgepakt draait niet meer.
        'ai_stale_seconds' => 600,

        // Hoe lang een geüploade referentie voor "Genereer met AI" op de
        // publieke schijf mag blijven staan als de actie hem niet zelf
        // weghaalde, in minuten.
        'ai_reference_minutes' => 60,
    ],
];
