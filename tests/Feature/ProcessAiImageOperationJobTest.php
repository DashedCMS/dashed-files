<?php

use Mockery as m;
use Dashed\DashedFiles\Models\AiImageOperation;
use Dashed\DashedFiles\Services\AiImageOperations;
use Dashed\DashedFiles\Jobs\ProcessAiImageOperation;

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

it('gooit bij een mislukte bewerking en zet de status pas op failed via de failed() hook', function () {
    $service = m::mock(AiImageOperations::class);
    $service->shouldReceive('sourceUrl')->andReturn('https://cdn.test/source.jpg');
    $service->shouldReceive('removeBackground')->once()->andReturn(null);
    app()->instance(AiImageOperations::class, $service);

    $op = AiImageOperation::create([
        'type' => AiImageOperation::TYPE_REMOVE_BACKGROUND,
        'status' => AiImageOperation::STATUS_QUEUED,
        'source_media_id' => 42,
    ]);

    $job = new ProcessAiImageOperation($op->id);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    // handle() heeft nog niet FAILED gezet (dat doet de failed()-hook na de laatste poging).
    expect($op->fresh()->status)->toBe(AiImageOperation::STATUS_PROCESSING);

    $job->failed(new RuntimeException('boom'));

    $op->refresh();
    expect($op->status)->toBe(AiImageOperation::STATUS_FAILED);
    expect($op->error)->not->toBeNull();
});

it('verwerkt een generate-operatie en stuurt de params door', function () {
    $service = m::mock(AiImageOperations::class);
    $service->shouldReceive('generate')
        ->once()
        ->with('een rode schoen', '4:5', null, null)
        ->andReturn(321);
    app()->instance(AiImageOperations::class, $service);

    $op = AiImageOperation::create([
        'type' => AiImageOperation::TYPE_GENERATE,
        'status' => AiImageOperation::STATUS_QUEUED,
        'params' => ['prompt' => 'een rode schoen', 'ratio' => '4:5'],
    ]);

    (new ProcessAiImageOperation($op->id))->handle();

    $op->refresh();
    expect($op->status)->toBe(AiImageOperation::STATUS_DONE);
    expect($op->result_media_id)->toBe(321);
});
