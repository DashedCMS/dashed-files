<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // Wie een item in de prullenbak gooide: `panel` of `code` (zie
        // MediaTrashOrigin). Wat code weggooide ruimt de opruimer na een
        // termijn vanzelf op. Nullable en zonder vulling: een rij die al in
        // de prullenbak lag heeft geen herkomst en valt daar nooit onder.
        // De gebruiker staat al in `deleted_by_user_id`.
        if (Schema::hasTable('filament_media_library') && ! Schema::hasColumn('filament_media_library', 'trashed_via')) {
            Schema::table('filament_media_library', fn (Blueprint $table) => $table->string('trashed_via', 16)->nullable());
        }
    }

    public function down(): void
    {
        // Bewust leeg: dit pakket haalt bij terugdraaien nooit kolommen van de mediatabellen weg.
    }
};
