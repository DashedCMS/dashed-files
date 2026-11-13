<?php

namespace Dashed\DashedFiles\Classes\Access;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;

/**
 * De rechten van de mediabibliotheek. Eén plek die van een handeling
 * (`view`, `upload`, `edit`, ...) naar een recht gaat en het antwoord geeft.
 *
 * Ze gelden voor wat een ingelogde paneelgebruiker in de mediaschermen doet
 * (de beheerpagina, het keuzevenster, het mediaveld en de routes van het
 * pakket). De inname zelf (MediaLibrary::ingest(), uploadFromPath(), de
 * chat, de API, een commando of een wachtrijtaak) vraagt niets: daar is
 * geen paneelgebruiker.
 *
 * Een superadmin heeft elk recht via Gate::before in dashed-core; een rol
 * krijgt ze bij Gebruikers, Rollen. De migratie gaf de vijf aan elke rol die
 * er op dat moment was. Een rol die daarna wordt aangemaakt heeft ze niet
 * tot iemand ze aanvinkt.
 */
final class MediaRights
{
    public const VIEW = 'view_media';

    public const UPLOAD = 'upload_media';

    public const EDIT = 'edit_media';

    public const DELETE = 'delete_media';

    public const FOLDERS = 'manage_media_folders';

    /** @var array<int, string> de vijf van deze fase; `purge_media` bestond al */
    public const PERMISSIONS = [self::VIEW, self::UPLOAD, self::EDIT, self::DELETE, self::FOLDERS];

    /**
     * Van de handeling die een scherm vraagt naar het recht. `manage` is
     * lezen dat alleen de beheerpagina doet (waar gebruikt, de prullenbak
     * van mappen, de stand van de pomp, een zip): hetzelfde recht als
     * bekijken, maar een keuzevenster laat het niet door.
     *
     * @var array<string, string>
     */
    public const ABILITIES = [
        'view' => self::VIEW,
        'manage' => self::VIEW,
        'upload' => self::UPLOAD,
        'edit' => self::EDIT,
        'delete' => self::DELETE,
        'folders' => self::FOLDERS,
        'purge' => MediaLibrary::PURGE_PERMISSION,
    ];

    /**
     * @return array<string, string> recht => omschrijving voor het rollenscherm
     */
    public static function labels(): array
    {
        return [
            self::VIEW => __('Mediabibliotheek bekijken en bestanden kiezen'),
            self::UPLOAD => __('Bestanden uploaden'),
            self::EDIT => __('Bestanden bewerken (naam, teksten, tags, focuspunt, vervangen, verplaatsen)'),
            self::DELETE => __('Bestanden naar de prullenbak verplaatsen en terugzetten'),
            self::FOLDERS => __('Mappen beheren (aanmaken, verplaatsen, sites en privé)'),
        ];
    }

    /**
     * Of deze gebruiker dit mag. Zonder gebruiker, zonder paneeltoegang of
     * bij een onbekende handeling: nee.
     */
    public static function allows(string $ability, ?Authenticatable $user): bool
    {
        $permission = self::ABILITIES[$ability] ?? null;

        if ($permission === null || $user === null) {
            return false;
        }

        $panel = rescue(fn () => Filament::getCurrentOrDefaultPanel(), null, false);

        if ($user instanceof FilamentUser && $panel && ! $user->canAccessPanel($panel)) {
            return false;
        }

        // Definitief verwijderen was al een echt recht, ook voor de migraties van deze fase.
        if ($ability === 'purge') {
            return MediaLibrary::mayPurge($user);
        }

        // Tussen uitrollen en migreren zijn de rechten nog niet uitgedeeld:
        // tot dan geldt wat altijd gold, paneeltoegang. De migratie die ze
        // uitdeelt draait voor die van de kolommen.
        // Dat is een ja op wat we weten, nooit op wat ontbreekt: zonder
        // paneel of zonder een gebruiker die het paneel in mag is het nee.
        if (! self::enforced()) {
            return $user instanceof FilamentUser && $panel !== null && $user->canAccessPanel($panel);
        }

        return Gate::forUser($user)->allows($permission);
    }

    /** De ingelogde paneelgebruiker. */
    public static function user(): ?Authenticatable
    {
        return rescue(fn () => Filament::auth()->user(), null, false) ?? auth()->user();
    }

    public static function check(string $ability): bool
    {
        return self::allows($ability, self::user());
    }

    public static function enforced(): bool
    {
        return BrowseSchema::hasAccess();
    }
}
