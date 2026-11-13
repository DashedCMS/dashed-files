<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Dashed\DashedFiles\Jobs\BackfillMediaMirror;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('filament_media_library') || ! Schema::hasColumn('filament_media_library', 'file_name')) {
            return;
        }

        // Zonder een enkel item van voor de spiegelkolommen komt er niets in de wachtrij.
        if (DB::table('filament_media_library')->whereNull('file_name')->exists()) {
            BackfillMediaMirror::dispatch();
        }
    }

    public function down(): void
    {
        // Niets terug te zetten: de kolommen blijven gevuld.
    }
};
