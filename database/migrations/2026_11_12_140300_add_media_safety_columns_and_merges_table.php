<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // Wanneer de site een weggegooid bestand voor het laatst nog heeft
        // uitgeleverd. Zolang dit gevuld is ruimt niets het automatisch op.
        if (Schema::hasTable('filament_media_library') && ! Schema::hasColumn('filament_media_library', 'trashed_served_at')) {
            Schema::table('filament_media_library', fn (Blueprint $table) => $table->timestamp('trashed_served_at')->nullable());
        }

        // Een verwijzing die op de naam van een sleutel of op een toevallig
        // gelijk getal is aangenomen. Telt mee voor "in gebruik", wordt
        // nooit herschreven.
        if (Schema::hasTable('dashed__media_usages') && ! Schema::hasColumn('dashed__media_usages', 'is_guess')) {
            Schema::table('dashed__media_usages', fn (Blueprint $table) => $table->boolean('is_guess')->default(false));
        }

        if (! Schema::hasTable('dashed__media_merges')) {
            Schema::create('dashed__media_merges', function (Blueprint $table) {
                $table->id();
                $table->string('batch', 36);
                $table->unsignedBigInteger('kept_item_id');
                $table->unsignedBigInteger('merged_item_id');
                // Leeg voor de regel die alleen zegt "dit item is samengevoegd".
                $table->string('model_type')->nullable();
                $table->unsignedBigInteger('model_id')->nullable();
                // De precieze plek binnen het attribuut (images.2, content.0.text#1).
                $table->string('field', 191)->nullable();
                $table->string('locale', 10)->default('');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index('batch', 'dmm_batch_idx');
                $table->index('merged_item_id', 'dmm_merged_idx');
            });
        }
    }

    public function down(): void
    {
        // De kolom op de mediatabel blijft staan; alleen de eigen tabellen gaan terug.
        Schema::dropIfExists('dashed__media_merges');

        if (Schema::hasTable('dashed__media_usages') && Schema::hasColumn('dashed__media_usages', 'is_guess')) {
            Schema::table('dashed__media_usages', fn (Blueprint $table) => $table->dropColumn('is_guess'));
        }
    }
};
