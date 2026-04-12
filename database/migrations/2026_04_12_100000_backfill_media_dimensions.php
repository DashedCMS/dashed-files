<?php

use Dashed\DashedFiles\Jobs\BackfillMediaDimensionsJob;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        BackfillMediaDimensionsJob::dispatch(0, 50)->onQueue('default');
    }

    public function down(): void
    {
        // Nothing to reverse — dimensions stay in custom_properties
    }
};
