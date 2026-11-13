<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Fase 10, vervolg: de voortgang van een verhuizing tussen de publieke en de
 * privé-schijf. `relocate_since` zegt sinds wanneer een item wacht,
 * `relocate_attempts` hoe vaak het mislukte, en `relocate_gave_up_at` dat de
 * minuuttaak het heeft opgegeven (het item blijft dan dicht tot iemand het
 * opnieuw laat proberen).
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('filament_media_library')) {
            return;
        }

        Schema::table('filament_media_library', function (Blueprint $table) {
            if (! Schema::hasColumn('filament_media_library', 'relocate_attempts')) {
                $table->unsignedSmallInteger('relocate_attempts')->nullable();
            }

            if (! Schema::hasColumn('filament_media_library', 'relocate_since')) {
                $table->timestamp('relocate_since')->nullable();
            }

            if (! Schema::hasColumn('filament_media_library', 'relocate_gave_up_at')) {
                $table->timestamp('relocate_gave_up_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Bewust leeg: de kolommen dragen welke verhuizingen zijn opgegeven.
    }
};
