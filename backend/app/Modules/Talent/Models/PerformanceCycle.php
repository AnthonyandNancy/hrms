<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property \Carbon\CarbonImmutable|null $recorded_at
 */
final class PerformanceCycle extends Model
{
    protected $table = 'performance_cycles';

    /** @var list<string> */
    protected $fillable = ['name', 'period_from', 'period_to', 'status', 'published_at', 'published_by', 'recorded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'published_by' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
