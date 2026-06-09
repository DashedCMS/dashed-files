<?php

use Illuminate\Support\Facades\Bus;
use Dashed\DashedFiles\Models\AiImageOperation;
use Dashed\DashedFiles\Jobs\ProcessAiImageOperation;
use Dashed\DashedFiles\Services\AiImageOperations;

it('slaat context op bij dispatchOne', function () {
    Bus::fake();

    $context = ['handler' => 'content_studio_image', 'custom_block_id' => 5, 'locale' => 'nl', 'block_key' => 0, 'field' => 'image'];

    $id = (new AiImageOperations())->dispatchOne(
        type: AiImageOperation::TYPE_GENERATE,
        params: ['prompt' => 'strand', 'ratio' => '1:1'],
        context: $context,
    );

    expect(AiImageOperation::find($id)->context)->toBe($context);
    Bus::assertDispatched(ProcessAiImageOperation::class);
});
