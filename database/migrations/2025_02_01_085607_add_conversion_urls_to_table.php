<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('filament_media_library', 'conversion_urls')) {
            Schema::table('filament_media_library', function (Blueprint $table) {
                $table->json('conversion_urls')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('filament_media', function (Blueprint $table) {
            //
        });
    }
};
