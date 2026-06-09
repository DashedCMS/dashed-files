<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_image_operations', function (Blueprint $table) {
            $table->json('context')->nullable()->after('params');
        });
    }

    public function down(): void
    {
        Schema::table('ai_image_operations', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
