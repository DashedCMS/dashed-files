<?php

use Illuminate\Support\Facades\Http;
use Dashed\DashedFiles\Services\AiImageOperations;
use Mockery as m;

it('rapporteert isConfigured als false zonder fal-sleutel', function () {
    // Customsetting::get geeft null terug zonder ingestelde sleutel/tabel,
    // dus isConfigured hoort false te zijn. Dit raakt geen externe diensten.
    expect(AiImageOperations::isConfigured())->toBeFalse();
});

it('verwijdert de achtergrond via birefnet en importeert het resultaat', function () {
    Http::fake([
        'fal.run/fal-ai/birefnet' => Http::response(['image' => ['url' => 'https://cdn.test/cutout.png']], 200),
    ]);

    $service = m::mock(AiImageOperations::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('apiKey')->andReturn('test-key');
    $service->shouldReceive('import')
        ->once()
        ->with('https://cdn.test/cutout.png', 'ai-edited')
        ->andReturn(777);

    $resultId = $service->removeBackground('https://cdn.test/source.jpg');

    expect($resultId)->toBe(777);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'fal-ai/birefnet')
            && $request['image_url'] === 'https://cdn.test/source.jpg';
    });
});

it('upscalet via clarity-upscaler en importeert het resultaat', function () {
    Http::fake([
        'fal.run/fal-ai/clarity-upscaler' => Http::response(['image' => ['url' => 'https://cdn.test/big.png']], 200),
    ]);

    $service = m::mock(AiImageOperations::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('apiKey')->andReturn('test-key');
    $service->shouldReceive('import')
        ->once()
        ->with('https://cdn.test/big.png', 'ai-edited')
        ->andReturn(888);

    expect($service->upscale('https://cdn.test/source.jpg'))->toBe(888);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'clarity-upscaler')
        && $request['image_url'] === 'https://cdn.test/source.jpg');
});
