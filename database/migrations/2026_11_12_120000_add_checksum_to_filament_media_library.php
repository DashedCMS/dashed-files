<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('filament_media_library')) {
            return;
        }

        // De grootte staat ook op de mediarij, maar een dubbelcontrole moet
        // zonder join op één index kunnen: eerst de grootte (goedkoop en al
        // bekend voor er gehasht is), dan de hash.
        if (! Schema::hasColumn('filament_media_library', 'size')) {
            Schema::table('filament_media_library', fn (Blueprint $table) => $table->unsignedBigInteger('size')->nullable());
        }

        if (! Schema::hasColumn('filament_media_library', 'checksum')) {
            Schema::table('filament_media_library', fn (Blueprint $table) => $table->char('checksum', 64)->nullable());
        }

        if (! Schema::hasIndex('filament_media_library', 'fml_size_checksum_idx')) {
            Schema::table('filament_media_library', fn (Blueprint $table) => $table->index(['size', 'checksum'], 'fml_size_checksum_idx'));
        }
    }

    public function down(): void
    {
        // Bewust leeg: dit pakket haalt bij terugdraaien nooit kolommen van de mediatabellen weg.
    }
};
