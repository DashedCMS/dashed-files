<?php

use Mockery as m;
use Illuminate\Support\Facades\Event;
use Dashed\DashedFiles\Models\AiImageOperation;
use Dashed\DashedFiles\Jobs\ProcessAiImageOperation;
use Dashed\DashedFiles\Services\AiImageOperations;
use Dashed\DashedFiles\Events\AiImageOperationCompleted;

it('vuurt AiImageOperationCompleted bij een geslaagde operatie', function () {
    Event::fake([AiImageOperationCompleted::class]);

    $service = m::mock(AiImageOperations::class);
    $service->shouldReceive('sourceUrl')->andReturn('https://cdn.test/x.jpg');
    $service->shouldReceive('upscale')->once()->andReturn(900);
    app()->instance(AiImageOperations::class, $service);

    $op = AiImageOperation::create([
        'type' => AiImageOperation::TYPE_UPSCALE,
        'status' => AiImageOperation::STATUS_QUEUED,
        'source_media_id' => 1,
    ]);

    (new ProcessAiImageOperation($op->id))->handle();

    Event::assertDispatched(AiImageOperationCompleted::class, function ($event) use ($op) {
        return $event->operation->id === $op->id
            && $event->operation->status === AiImageOperation::STATUS_DONE
            && $event->operation->result_media_id === 900;
    });
});

it('vuurt het event niet wanneer de operatie geen resultaat oplevert', function () {
    Event::fake([AiImageOperationCompleted::class]);

    $service = m::mock(AiImageOperations::class);
    $service->shouldReceive('sourceUrl')->andReturn('https://cdn.test/x.jpg');
    $service->shouldReceive('upscale')->once()->andReturn(null);
    app()->instance(AiImageOperations::class, $service);

    $op = AiImageOperation::create([
        'type' => AiImageOperation::TYPE_UPSCALE,
        'status' => AiImageOperation::STATUS_QUEUED,
        'source_media_id' => 1,
    ]);

    expect(fn () => (new ProcessAiImageOperation($op->id))->handle())->toThrow(RuntimeException::class);

    Event::assertNotDispatched(AiImageOperationCompleted::class);
});
