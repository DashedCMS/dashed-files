<?php

use Dashed\DashedFiles\Models\AiImageOperation;

it('persisteert een operatie en herkent of die klaar is', function () {
    $op = AiImageOperation::create([
        'type' => AiImageOperation::TYPE_UPSCALE,
        'status' => AiImageOperation::STATUS_QUEUED,
        'source_media_id' => 42,
        'params' => ['foo' => 'bar'],
    ]);

    expect($op->params)->toBe(['foo' => 'bar']);
    expect($op->isFinished())->toBeFalse();

    $op->update(['status' => AiImageOperation::STATUS_DONE]);
    expect($op->fresh()->isFinished())->toBeTrue();
});
