<?php

namespace Dashed\DashedFiles\Classes\Browse;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Dashed\DashedFiles\Models\MediaItem;

/**
 * Wie een item in de prullenbak gooide: een beheerder in het paneel
 * (`panel`) of code (`code`: een taak, commando, de scheduler, tinker, een
 * API-aanroep zonder paneelsessie). Het oude pakket verwijderde bij
 * `->delete()` meteen definitief, en code die daarop leunt (autoxl ververst
 * zo elke nacht zijn afbeeldingen) laat bij ons anders een prullenbak
 * achter die nooit leeg raakt. Wat code weggooide ruimt
 * MediaPrullenbakOpruimer na een termijn alsnog op.
 */
final class MediaTrashOrigin
{
    public const PANEL = 'panel';

    public const CODE = 'code';

    /** Gezet door een ingang die zeker weet waar hij vandaan komt (de mediaschermen). */
    private static ?string $forced = null;

    /**
     * Zonder expliciete ingang: `panel` als er via de sessie (de web-guard)
     * een gebruiker is ingelogd die het paneel mag gebruiken, anders `code`.
     * Bewust de web-guard en niet de standaardguard: een API-aanroep met een
     * token heeft geen paneelsessie. Een taak in een wachtrijwerker of een
     * commando heeft geen ingelogde gebruiker. Twijfel valt naar `panel`,
     * en dat ruimt nooit vanzelf op.
     */
    public static function current(): string
    {
        if (self::$forced !== null) {
            return self::$forced;
        }

        $user = rescue(fn () => Auth::guard('web')->user(), null, false);

        if ($user === null) {
            return self::CODE;
        }

        if (method_exists($user, 'canAccessPanel') && class_exists(\Filament\Facades\Filament::class)) {
            $panel = rescue(fn () => \Filament\Facades\Filament::getPanel('dashed'), null, false);

            if ($panel !== null) {
                return rescue(fn () => $user->canAccessPanel($panel), true, false) ? self::PANEL : self::CODE;
            }
        }

        return self::PANEL;
    }

    /** De gebruiker voor `deleted_by_user_id`, als die er via de sessie is. */
    public static function userId(): ?int
    {
        $id = rescue(fn () => Auth::guard('web')->id(), null, false);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Voert $callback uit met een vaste herkomst; daarna weer zoals het was.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function as(string $origin, callable $callback): mixed
    {
        $previous = self::$forced;
        self::$forced = $origin;

        try {
            return $callback();
        } finally {
            self::$forced = $previous;
        }
    }

    /**
     * De termijn voor wat code weggooide, of null als dat uitstaat.
     */
    public static function codePurgeDays(): ?int
    {
        $days = (int) config('dashed-files.trash.code_deleted_purge_days', 30);

        return $days > 0 ? $days : null;
    }

    /**
     * De dag waarop de opruimer dit item definitief weghaalt, als het de
     * wachttijd van code-verwijderingen volgt, anders null. Een rij van vóór
     * de kolom (zonder herkomst) valt hier nooit onder.
     */
    public static function codePurgeAt(MediaItem $item): ?CarbonInterface
    {
        $deletedAt = $item->getAttribute('deleted_at');

        if ($deletedAt === null || $item->getAttribute('trashed_via') !== self::CODE || ($days = self::codePurgeDays()) === null) {
            return null;
        }

        return $deletedAt->copy()->addDays($days);
    }

    /**
     * De eerste dag waarop een opruimer het item weghaalt: de algemene
     * termijn als `auto_purge` aanstaat ($purgeDays), of de termijn van wat
     * code weggooide, wat het eerst komt.
     */
    public static function purgeAt(MediaItem $item, ?int $purgeDays): ?CarbonInterface
    {
        $deletedAt = $item->getAttribute('deleted_at');

        if ($deletedAt === null) {
            return null;
        }

        $dates = array_filter([
            $purgeDays !== null ? $deletedAt->copy()->addDays($purgeDays) : null,
            self::codePurgeAt($item),
        ]);

        return $dates === [] ? null : min($dates);
    }
}
