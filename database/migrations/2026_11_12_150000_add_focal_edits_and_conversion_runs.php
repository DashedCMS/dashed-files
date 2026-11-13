<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('filament_media_library')) {
            // Het focuspunt in procenten (0-100). Leeg is het midden, zoals het altijd was.
            if (! Schema::hasColumn('filament_media_library', 'focal_x')) {
                Schema::table('filament_media_library', fn (Blueprint $table) => $table->unsignedTinyInteger('focal_x')->nullable());
            }

            if (! Schema::hasColumn('filament_media_library', 'focal_y')) {
                Schema::table('filament_media_library', fn (Blueprint $table) => $table->unsignedTinyInteger('focal_y')->nullable());
            }

            // Het recept van bijsnijden, draaien en spiegelen. Leeg: het bestand is onbewerkt.
            if (! Schema::hasColumn('filament_media_library', 'edits')) {
                Schema::table('filament_media_library', fn (Blueprint $table) => $table->json('edits')->nullable());
            }
        }

        if (! Schema::hasTable('dashed__media_conversion_runs')) {
            Schema::create('dashed__media_conversion_runs', function (Blueprint $table) {
                $table->id();
                $table->string('type', 20);
                $table->string('status', 20)->default('pending');
                $table->json('filters')->nullable();
                // Het laatste item dat verwerkt is: hervatten gaat hier verder.
                $table->unsignedBigInteger('cursor')->default(0);
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('done')->default(0);
                $table->unsignedInteger('failed')->default(0);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['type', 'status'], 'dmcr_type_status_idx');
            });
        }
    }

    public function down(): void
    {
        // De kolommen op de mediatabel blijven staan; alleen de eigen tabel gaat terug.
        Schema::dropIfExists('dashed__media_conversion_runs');
    }
};
