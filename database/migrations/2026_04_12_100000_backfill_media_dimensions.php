<?php

use Illuminate\Database\Migrations\Migration;
use Dashed\DashedFiles\Jobs\BackfillMediaDimensionsJob;

return new class () extends Migration {
    public function up(): void
    {
        BackfillMediaDimensionsJob::dispatch(0, 50)->onQueue('default');
    }

    public function down(): void
    {
        // Nothing to reverse - dimensions stay in custom_properties
    }
};
