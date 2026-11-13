<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    private const TABLE = 'filament_media_library';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // Spiegelkolommen van de mediarij: de lijst zoekt, filtert en
        // sorteert hierop zonder join. Alles nullable, het oude pakket kent
        // deze kolommen niet.
        $columns = [
            'title' => fn (Blueprint $table) => $table->string('title')->nullable(),
            'file_name' => fn (Blueprint $table) => $table->string('file_name')->nullable(),
            'mime_type' => fn (Blueprint $table) => $table->string('mime_type', 127)->nullable(),
            'width' => fn (Blueprint $table) => $table->unsignedInteger('width')->nullable(),
            'height' => fn (Blueprint $table) => $table->unsignedInteger('height')->nullable(),
            'source' => fn (Blueprint $table) => $table->string('source', 40)->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, $definition);
            }
        }

        $this->index(['mime_type'], 'fml_mime_type_idx');
        $this->index(['file_name'], 'fml_file_name_idx');
        $this->index(['created_at'], 'fml_created_at_idx');

        // Op MySQL heeft een foreign key al een index op zijn kolom, en
        // InnoDB zet de primaire sleutel achter elke index: (folder_id) is
        // daar al (folder_id, id). Alleen aanmaken waar nog niets met de
        // kolom begint.
        if (Schema::hasColumn(self::TABLE, 'folder_id') && ! $this->hasIndexStartingWith('folder_id')) {
            $this->index(['folder_id', 'id'], 'fml_folder_id_idx');
        }

        if (! $this->hasIndexStartingWith('uploaded_by_user_id')) {
            $this->index(['uploaded_by_user_id'], 'fml_uploader_idx');
        }
    }

    public function down(): void
    {
        // Bewust leeg: dit pakket haalt bij terugdraaien nooit kolommen van de mediatabellen weg.
    }

    private function index(array $columns, string $name): void
    {
        if (! Schema::hasIndex(self::TABLE, $name)) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->index($columns, $name));
        }
    }

    private function hasIndexStartingWith(string $column): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
};
