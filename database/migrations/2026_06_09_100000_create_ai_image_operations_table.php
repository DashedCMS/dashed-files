<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_image_operations', function (Blueprint $table) {
            $table->id();
            $table->string('type');                       // remove_background, upscale, edit, product_photo, generate
            $table->string('status')->default('queued');  // queued, processing, done, failed
            $table->unsignedBigInteger('source_media_id')->nullable();
            $table->unsignedBigInteger('result_media_id')->nullable();
            $table->json('params')->nullable();
            $table->uuid('batch_id')->nullable()->index();
            $table->string('site_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_image_operations');
    }
};
