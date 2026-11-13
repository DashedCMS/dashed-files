<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // In conversion_urls kon de terugval-url (medium of het origineel) van
        // een nog niet gegenereerd formaat voor altijd blijven staan, en die
        // kolom wordt als definitief gelezen. Eén keer leegmaken; de url's
        // worden bij het eerstvolgende gebruik opnieuw opgelost. De oude
        // cachesleutels (zonder versie) worden niet meer gelezen.
        if (Schema::hasTable('filament_media_library') && Schema::hasColumn('filament_media_library', 'conversion_urls')) {
            $last = (int) DB::table('filament_media_library')->max('id');

            // In bereiken van de sleutel: één UPDATE over de hele tabel houdt
            // op een grote bibliotheek alle rijen tegelijk vast.
            for ($from = 1; $from <= $last; $from += 1000) {
                DB::table('filament_media_library')
                    ->whereBetween('id', [$from, $from + 999])
                    ->whereNotNull('conversion_urls')
                    ->update(['conversion_urls' => null]);
            }
        }
    }

    public function down(): void
    {
        // Niets terug te zetten: de kolom is een cache.
    }
};
