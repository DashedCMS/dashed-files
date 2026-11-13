<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Dashed\DashedFiles\Classes\Browse\MediaFolderTree;

return new class () extends Migration {
    private const TABLE = 'filament_media_library_folders';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // De id's van wortel tot en met de map zelf, als /3/17/42/: een
        // subboom is dan één LIKE op een prefix. Geen index: 1024 tekens
        // past niet in een MySQL-index en de mappentabel is klein.
        if (! Schema::hasColumn(self::TABLE, 'path')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->string('path', 1024)->nullable());
        }

        if (! Schema::hasColumn(self::TABLE, 'sort')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->integer('sort')->default(0));
        }

        if (class_exists(MediaFolderTree::class)) {
            app(MediaFolderTree::class)->rebuildPaths();
        }
    }

    public function down(): void
    {
        // Bewust leeg: dit pakket haalt bij terugdraaien nooit kolommen van de mediatabellen weg.
    }
};
