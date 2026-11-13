<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Dashed\DashedFiles\Classes\Usage\MediaUsageIndexer;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('dashed__media_usages')) {
            return;
        }

        // Eén keer de nulmeting klaarzetten: per geregistreerd model een taak
        // in de wachtrij. Een fout hier mag de uitrol niet tegenhouden;
        // `dashed:media-usages` doet hetzelfde met de hand.
        rescue(fn () => app(MediaUsageIndexer::class)->queueBaseline());
    }

    public function down(): void
    {
        // Niets terug te zetten: de index mag gevuld blijven.
    }
};
