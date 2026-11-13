<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('dashed__media_conversion_runs')) {
            return;
        }

        // Items die een run bewust oversloeg: te groot, of al twee keer mislukt.
        if (! Schema::hasColumn('dashed__media_conversion_runs', 'skipped')) {
            Schema::table('dashed__media_conversion_runs', fn (Blueprint $table) => $table->unsignedInteger('skipped')->default(0));
        }

        // De keten van taken: alleen de taak met dit kenmerk mag de run
        // verder zetten, en de hartslag zegt wanneer dat voor het laatst gebeurde.
        if (! Schema::hasColumn('dashed__media_conversion_runs', 'owner')) {
            Schema::table('dashed__media_conversion_runs', fn (Blueprint $table) => $table->string('owner', 40)->nullable());
        }

        if (! Schema::hasColumn('dashed__media_conversion_runs', 'heartbeat_at')) {
            Schema::table('dashed__media_conversion_runs', fn (Blueprint $table) => $table->timestamp('heartbeat_at')->nullable());
        }
    }

    public function down(): void
    {
        // De eigen tabel gaat in zijn geheel terug in de migratie die hem aanmaakt.
    }
};
