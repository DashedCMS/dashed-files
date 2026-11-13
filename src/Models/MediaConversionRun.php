<?php

namespace Dashed\DashedFiles\Models;

use Illuminate\Database\Eloquent\Model;
use Dashed\DashedFiles\Classes\Browse\BrowseSchema;

/**
 * Eén ronde van de pomp die formaten (opnieuw) genereert of opruimt.
 */
class MediaConversionRun extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const ACTIVE = [self::PENDING, self::RUNNING];

    protected $table = BrowseSchema::RUNS;

    protected $guarded = [];

    protected $casts = [
        'filters' => 'array',
        'cursor' => 'integer',
        'total' => 'integer',
        'done' => 'integer',
        'failed' => 'integer',
        'skipped' => 'integer',
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * @return array<int, int>
     */
    public function itemIds(): array
    {
        return array_values(array_map('intval', (array) ($this->filters['ids'] ?? [])));
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_values(array_map('strval', (array) ($this->filters['names'] ?? [])));
    }
}
