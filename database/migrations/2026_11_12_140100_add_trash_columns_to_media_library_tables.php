<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // Alles nullable: het oude pakket schrijft naar dezelfde tabellen en
        // kent deze kolommen niet.
        $this->add('filament_media_library', [
            'deleted_at' => fn (Blueprint $table) => $table->timestamp('deleted_at')->nullable(),
            'deleted_by_user_id' => fn (Blueprint $table) => $table->unsignedBigInteger('deleted_by_user_id')->nullable(),
            // Wat in dezelfde handeling is weggegooid gaat ook samen terug.
            'trash_batch' => fn (Blueprint $table) => $table->string('trash_batch', 36)->nullable(),
        ], ['deleted_at' => 'fml_deleted_at_idx', 'trash_batch' => 'fml_trash_batch_idx']);

        $this->add('filament_media_library_folders', [
            'deleted_at' => fn (Blueprint $table) => $table->timestamp('deleted_at')->nullable(),
            'trash_batch' => fn (Blueprint $table) => $table->string('trash_batch', 36)->nullable(),
        ], ['deleted_at' => 'fmlf_deleted_at_idx', 'trash_batch' => 'fmlf_trash_batch_idx']);

        // De slimme map met dubbele bestanden groepeert hierop.
        if (Schema::hasTable('filament_media_library') && Schema::hasColumn('filament_media_library', 'checksum')
            && Schema::hasColumn('filament_media_library', 'size') && ! Schema::hasIndex('filament_media_library', 'fml_size_checksum_idx')
            && ! $this->hasIndexOn('filament_media_library', ['size', 'checksum'])) {
            Schema::table('filament_media_library', fn (Blueprint $table) => $table->index(['size', 'checksum'], 'fml_size_checksum_idx'));
        }
    }

    public function down(): void
    {
        // Bewust leeg: dit pakket haalt bij terugdraaien nooit kolommen van de mediatabellen weg.
    }

    private function add(string $table, array $columns, array $indexes): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn($table, $column)) {
                Schema::table($table, $definition);
            }
        }

        foreach ($indexes as $column => $name) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index([$column], $name));
            }
        }
    }

    private function hasIndexOn(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (array_slice($index['columns'] ?? [], 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }
};
