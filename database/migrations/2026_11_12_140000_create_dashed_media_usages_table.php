<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    private const TABLE = 'dashed__media_usages';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('media_item_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            // Het attribuut, of het pad binnen de JSON (content.3.image).
            $table->string('field', 191);
            // Lege tekst en geen null voor "geen taal": MySQL ziet twee
            // null-waarden in een unieke index als verschillend, en dan
            // staat dezelfde verwijzing er na elke herindexering dubbel in.
            $table->string('locale', 10)->default('');
            $table->string('site_id', 64)->nullable();
            $table->timestamps();

            // Korte namen: de standaardnaam is op MySQL te lang.
            $table->unique(['media_item_id', 'model_type', 'model_id', 'field', 'locale'], 'dmu_ref_unique');
            $table->index(['model_type', 'model_id'], 'dmu_model_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
