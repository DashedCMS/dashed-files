<?php

namespace Dashed\DashedFiles\Classes\Browse;

final class MediaItemPage
{
    /**
     * @param  array<int, array<string, mixed>>  $items  zie MediaItemPayload::for()
     * @param  int|null  $total  alleen gevuld na MediaItemQuery::withTotal()
     */
    public function __construct(
        public readonly array $items,
        public readonly ?int $total,
        public readonly bool $hasMore,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'total' => $this->total,
            'hasMore' => $this->hasMore,
            'page' => $this->page,
            'perPage' => $this->perPage,
        ];
    }
}
