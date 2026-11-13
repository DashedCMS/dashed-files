<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Dashed\DashedFiles\Jobs\RegenerateHeightenConversionsJob;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('filament_media_library') || ! Schema::hasColumn('filament_media_library', 'conversions')) {
            return;
        }

        // Bestaande heighten-bestanden zijn op breedte gemaakt; zie de taak.
        // Zonder een enkel item met heighten komt er niets in de wachtrij.
        if (DB::table('filament_media_library')->where('conversions', 'like', '%heighten%')->exists()) {
            RegenerateHeightenConversionsJob::dispatch();
        }
    }

    public function down(): void
    {
        // Niets terug te zetten: de bestanden zijn opnieuw gemaakt.
    }
};
