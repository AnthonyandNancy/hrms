<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class PerformanceReview extends Model
{
    protected $table = 'performance_reviews';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'cycle_id', 'employment_id', 'manager_employment_id', 'goals_text',
        'self_assessment', 'manager_assessment', 'self_rating', 'manager_rating',
        'calibrated_rating', 'status', 'submitted_at', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'cycle_id' => 'integer',
            'employment_id' => 'integer',
            'manager_employment_id' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
