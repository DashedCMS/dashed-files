<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Dashed\DashedFiles\Jobs\ProcessAiImageOperation;
use Dashed\DashedFiles\Models\AiImageOperation;
use Dashed\DashedFiles\Services\AiImageGenerator;
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

it('retoucheert via nano-banana/edit met de meegegeven prompt', function () {
    Http::fake([
        'fal.run/fal-ai/nano-banana/edit' => Http::response(['images' => [['url' => 'https://cdn.test/edited.png']]], 200),
    ]);

    $service = m::mock(AiImageOperations::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('apiKey')->andReturn('test-key');
    $service->shouldReceive('import')
        ->once()
        ->with('https://cdn.test/edited.png', 'ai-edited')
        ->andReturn(999);

    expect($service->edit('https://cdn.test/source.jpg', 'verwijder de koffievlek'))->toBe(999);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'nano-banana/edit')
            && $request['prompt'] === 'verwijder de koffievlek'
            && $request['image_urls'] === ['https://cdn.test/source.jpg'];
    });
});

it('maakt een product-foto via een bg-removal plus studio-edit pipeline', function () {
    Http::fake([
        'fal.run/fal-ai/birefnet' => Http::response(['image' => ['url' => 'https://cdn.test/cutout.png']], 200),
        'fal.run/fal-ai/nano-banana/edit' => Http::response(['images' => [['url' => 'https://cdn.test/studio.png']]], 200),
    ]);

    $service = m::mock(AiImageOperations::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('apiKey')->andReturn('test-key');
    $service->shouldReceive('import')
        ->once()
        ->with('https://cdn.test/studio.png', 'ai-edited')
        ->andReturn(1001);

    expect($service->productPhoto('https://cdn.test/source.jpg'))->toBe(1001);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'nano-banana/edit')
            && $request['image_urls'] === ['https://cdn.test/cutout.png'];
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'fal-ai/birefnet')
            && $request['image_url'] === 'https://cdn.test/source.jpg';
    });

    Http::assertSentCount(2);
});

it('delegeert generatie naar AiImageGenerator', function () {
    $generator = m::mock(AiImageGenerator::class);
    $generator->shouldReceive('generate')
        ->once()
        ->with('een rode schoen', '1:1', null, 'ai-generated', null)
        ->andReturn(2002);
    app()->instance(AiImageGenerator::class, $generator);

    $service = new AiImageOperations();

    expect($service->generate('een rode schoen'))->toBe(2002);
});

it('maakt records aan en dispatcht een job per media-item bij bulk', function () {
    Bus::fake();

    $service = new AiImageOperations();
    $batchId = $service->dispatchBulk(
        type: AiImageOperation::TYPE_UPSCALE,
        sourceMediaIds: [10, 11, 12],
    );

    expect(AiImageOperation::where('batch_id', $batchId)->count())->toBe(3);
    expect(AiImageOperation::where('batch_id', $batchId)->where('type', 'upscale')->count())->toBe(3);

    Bus::assertDispatchedTimes(ProcessAiImageOperation::class, 3);
});
