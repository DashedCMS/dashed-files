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

        // Fase 9b: wat van een video of pdf bekend is. Alles leeg tot de
        // wachtrijtaak (GenerateMediaPreview) het bestand bekeken heeft.
        if (! Schema::hasColumn('filament_media_library', 'duration')) {
            // Seconden, met duizendsten.
            Schema::table('filament_media_library', fn (Blueprint $table) => $table->decimal('duration', 10, 3)->nullable());
        }

        if (! Schema::hasColumn('filament_media_library', 'pages')) {
            Schema::table('filament_media_library', fn (Blueprint $table) => $table->unsignedInteger('pages')->nullable());
        }

        // Een eigen poster uit de bibliotheek. Bewust geen foreign key: het
        // leespad valt terug op de automatische voorvertoning als dat item weg is.
        if (! Schema::hasColumn('filament_media_library', 'poster_item_id')) {
            Schema::table('filament_media_library', fn (Blueprint $table) => $table->unsignedBigInteger('poster_item_id')->nullable()->index('fml_poster_item_idx'));
        }

        // Wanneer de taak dit bestand voor het laatst bekeken heeft, ook als
        // dat niets opleverde: zo komt een bestand waar niets uit te halen
        // valt niet bij elke ronde opnieuw aan de beurt.
        if (! Schema::hasColumn('filament_media_library', 'preview_checked_at')) {
            Schema::table('filament_media_library', fn (Blueprint $table) => $table->timestamp('preview_checked_at')->nullable());
        }
    }

    public function down(): void
    {
        // De kolommen op de mediatabel blijven staan.
    }
};
