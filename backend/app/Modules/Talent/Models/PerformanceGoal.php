<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class PerformanceGoal extends Model
{
    protected $table = 'performance_goals';

    /** @var list<string> */
    protected $fillable = [
        'cycle_id', 'employment_id', 'indicator_id', 'title',
        'target_value', 'weight', 'due_on', 'status', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cycle_id' => 'integer',
            'employment_id' => 'integer',
            'indicator_id' => 'integer',
            'weight' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
