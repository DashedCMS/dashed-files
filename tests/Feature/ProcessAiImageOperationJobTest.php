<?php

use Mockery as m;
use Dashed\DashedFiles\Models\AiImageOperation;
use Dashed\DashedFiles\Jobs\ProcessAiImageOperation;
use Dashed\DashedFiles\Services\AiImageOperations;

it('verwerkt een upscale-operatie en zet die op done', function () {
    $service = m::mock(AiImageOperations::class);
    $service->shouldReceive('sourceUrl')->andReturn('https://cdn.test/source.jpg');
    $service->shouldReceive('upscale')
        ->once()
        ->with('https://cdn.test/source.jpg', null)
        ->andReturn(555);
    app()->instance(AiImageOperations::class, $service);

    $op = AiImageOperation::create([
        'type' => AiImageOperation::TYPE_UPSCALE,
        'status' => AiImageOperation::STATUS_QUEUED,
        'source_media_id' => 42,
    ]);

    (new ProcessAiImageOperation($op->id))->handle();

    $op->refresh();
    expect($op->status)->toBe(AiImageOperation::STATUS_DONE);
    expect($op->result_media_id)->toBe(555);
});

it('zet de operatie op failed wanneer de service niets teruggeeft', function () {
    $service = m::mock(AiImageOperations::class);
    $service->shouldReceive('sourceUrl')->andReturn('https://cdn.test/source.jpg');
    $service->shouldReceive('removeBackground')->once()->andReturn(null);
    app()->instance(AiImageOperations::class, $service);

    $op = AiImageOperation::create([
        'type' => AiImageOperation::TYPE_REMOVE_BACKGROUND,
        'status' => AiImageOperation::STATUS_QUEUED,
        'source_media_id' => 42,
    ]);

    (new ProcessAiImageOperation($op->id))->handle();

    $op->refresh();
    expect($op->status)->toBe(AiImageOperation::STATUS_FAILED);
    expect($op->error)->not->toBeNull();
});
