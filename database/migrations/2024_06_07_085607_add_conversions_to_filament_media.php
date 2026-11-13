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
        Schema::table('filament_media_library', function (Blueprint $table) {
            $table->json('conversions')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Bewust leeg: zonder deze kolom geeft elk leespad van de mediabibliotheek een SQL-fout.
    }
};
