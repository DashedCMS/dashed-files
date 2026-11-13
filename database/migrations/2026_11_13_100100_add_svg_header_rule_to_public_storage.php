<?php

use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Fase 9b: op een lokale publieke schijf kan een svg die rechtstreeks
     * geopend wordt een script uitvoeren. Het .htaccess van de publieke
     * opslagmap krijgt een gemarkeerd blok met een header die dat uitzet.
     * Een bestaand, zelf aangepast .htaccess wordt aangevuld en niet
     * overschreven. Bestaande svg's opschonen gebeurt niet hier maar met
     * `dashed:media-sanitize-svg`.
     */
    public function up(): void
    {
        // Een testrun hoort niet in de opslagmap van het project te schrijven.
        if (app()->runningUnitTests()) {
            return;
        }

        $class = 'Dashed\\DashedCore\\Classes\\UploadSecurity';

        if (class_exists($class) && method_exists($class, 'ensureSvgHeaders')) {
            rescue(fn () => $class::ensureSvgHeaders());
        }
    }

    public function down(): void
    {
        // Het blok mag blijven staan.
    }
};
