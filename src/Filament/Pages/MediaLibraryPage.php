<?php

namespace Dashed\DashedFiles\Filament\Pages;

use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Dashed\DashedFiles\Classes\MediaLibrary;
use Dashed\DashedFiles\Livewire\Concerns\BrowsesMedia;
use Dashed\DashedFiles\Livewire\Concerns\UsesMediaSources;
use Dashed\DashedFiles\Livewire\Concerns\ReceivesMediaUploads;

/**
 * De beheerpagina van de eigen mediabibliotheek. De pagina rendert alleen
 * de schil; lijst en boom komen als data via BrowsesMedia.
 */
class MediaLibraryPage extends Page
{
    use BrowsesMedia;
    use ReceivesMediaUploads;
    use UsesMediaSources;

    /**
     * Zolang de plugin van het pakket geregistreerd is houdt die de bekende
     * slug `media-browser` en staat deze pagina op `media`. Draait de eigen
     * motor al bij het opstarten, dan is de plugin er niet en neemt deze
     * pagina de bekende URL over. De stand ligt vast bij het opstarten
     * (DashedFilesServiceProvider), want de routes worden dan gebouwd.
     */
    public static bool $ownsLegacySlug = false;

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return static::$ownsLegacySlug ? 'media-browser' : 'media';
    }

    protected string $view = 'dashed-files::media.page';

    /**
     * Het item dat bij het laden open moet staan (`?item=<id>`). Een
     * mediaveld linkt zo naar de ene plek waar een bestand vervangen wordt.
     */
    #[\Livewire\Attributes\Locked]
    public ?int $openItem = null;

    public function mount(): void
    {
        $item = request()->query('item');

        $this->openItem = is_string($item) && ctype_digit($item) && strlen($item) <= 18 && (int) $item > 0 ? (int) $item : null;
    }

    public static function getNavigationLabel(): string
    {
        return __('Mediabibliotheek');
    }

    public function getTitle(): string | Htmlable
    {
        return __('Mediabibliotheek');
    }

    // Groep en pictogram gelijk aan MediaHelper::plugin(), zodat de pagina
    // op de plek van de oude staat.
    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-camera';
    }

    public static function getActiveNavigationIcon(): string
    {
        return 'heroicon-s-camera';
    }

    public function getMaxContentWidth(): Width | string | null
    {
        return Width::Full;
    }

    // Zonder het recht view_media geen menu-item; de route weigert dan ook (canAccess).
    public static function shouldRegisterNavigation(): bool
    {
        return MediaLibrary::usesOwnEngine() && (! auth()->check() || static::mayManage('view'));
    }

    public static function canAccess(): bool
    {
        return MediaLibrary::usesOwnEngine() && static::mayManage('view');
    }
}
