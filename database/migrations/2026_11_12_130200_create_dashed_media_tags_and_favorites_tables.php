<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // Geen foreign keys naar de itemtabel: die wordt later hernoemd.
        // De koppelingen van een verwijderd item ruimt de code op.
        if (! Schema::hasTable('dashed__media_tags')) {
            Schema::create('dashed__media_tags', function (Blueprint $table) {
                $table->id();
                $table->string('name', 50)->unique('dmt_name_unique');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('dashed__media_item_tag')) {
            Schema::create('dashed__media_item_tag', function (Blueprint $table) {
                $table->unsignedBigInteger('media_item_id');
                $table->unsignedBigInteger('media_tag_id');
                $table->primary(['media_item_id', 'media_tag_id'], 'dmit_primary');
                $table->index('media_tag_id', 'dmit_tag_idx');
            });
        }

        if (! Schema::hasTable('dashed__media_favorites')) {
            Schema::create('dashed__media_favorites', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('media_item_id');
                $table->timestamp('created_at')->nullable();
                $table->primary(['user_id', 'media_item_id'], 'dmf_primary');
                $table->index('media_item_id', 'dmf_item_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dashed__media_favorites');
        Schema::dropIfExists('dashed__media_item_tag');
        Schema::dropIfExists('dashed__media_tags');
    }
};
