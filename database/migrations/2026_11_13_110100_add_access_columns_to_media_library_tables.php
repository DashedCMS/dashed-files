<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Fase 10: sites en privé. Op de map staat wat een beheerder instelt
 * (`site_ids`, `is_private`); op het item staat hoe het nu behandeld wordt
 * (`is_private`: dicht) en waar zijn bestanden nog heen moeten
 * (`relocate_to`: `private` of `public`, leeg als er niets te verhuizen valt).
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('filament_media_library_folders')) {
            Schema::table('filament_media_library_folders', function (Blueprint $table) {
                if (! Schema::hasColumn('filament_media_library_folders', 'site_ids')) {
                    $table->json('site_ids')->nullable();
                }

                if (! Schema::hasColumn('filament_media_library_folders', 'is_private')) {
                    $table->boolean('is_private')->default(false);
                }
            });
        }

        if (Schema::hasTable('filament_media_library')) {
            Schema::table('filament_media_library', function (Blueprint $table) {
                if (! Schema::hasColumn('filament_media_library', 'is_private')) {
                    $table->boolean('is_private')->default(false);
                }

                if (! Schema::hasColumn('filament_media_library', 'relocate_to')) {
                    $table->string('relocate_to', 10)->nullable()->index('media_library_relocate_to_index');
                }
            });
        }
    }

    public function down(): void
    {
        // Bewust leeg: de kolommen dragen welke bestanden afgeschermd zijn.
    }
};
