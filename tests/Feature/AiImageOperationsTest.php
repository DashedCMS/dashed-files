<?php

use Illuminate\Support\Facades\Http;
use Dashed\DashedFiles\Services\AiImageOperations;

it('rapporteert isConfigured als false zonder fal-sleutel', function () {
    // Customsetting::get geeft null terug zonder ingestelde sleutel/tabel,
    // dus isConfigured hoort false te zijn. Dit raakt geen externe diensten.
    expect(AiImageOperations::isConfigured())->toBeFalse();
});
