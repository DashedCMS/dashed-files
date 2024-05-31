<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dashed__media_folders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('dashed__media_folders')
                ->nullOnDelete();

            $table->string('name');
            $table->string('path')
                ->nullable();

            $table->timestamps();
        });

        Schema::create('dashed__media_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('folder_id')
                ->nullable()
                ->constrained('dashed__media_folders')
                ->nullOnDelete();

            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('name');
            $table->text('file_name');
            $table->string('mime_type');
            $table->string('disk');
            $table->bigInteger('size');
            $table->json('generated_conversions')
                ->nullable();
            $table->json('responsive_urls')
                ->nullable();
            $table->string('caption')
                ->nullable();
            $table->string('alt_text')
                ->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_extra_options', function (Blueprint $table) {
            //
        });
    }
};
