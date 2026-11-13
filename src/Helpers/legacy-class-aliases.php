<?php

// Zie LegacyClassAliases. Via `autoload.files`, zodat de oude klassenamen
// werken vanaf het moment dat vendor/autoload.php geladen is, nog voor de
// eerste provider. Het bestand zelf met de hand: een testbootstrap kan de
// autoload-bestanden laden voordat er een autoloader staat.
if (! class_exists(\Dashed\DashedFiles\Classes\Legacy\LegacyClassAliases::class, false)) {
    require_once __DIR__ . '/../Classes/Legacy/LegacyClassAliases.php';
}

\Dashed\DashedFiles\Classes\Legacy\LegacyClassAliases::register();
