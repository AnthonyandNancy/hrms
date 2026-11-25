<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class PerformanceFeedback extends Model
{
    protected $table = 'performance_feedback';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'cycle_id', 'review_id', 'from_employment_id', 'to_employment_id',
        'feedback_type', 'rating', 'comments', 'anonymous', 'status', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'cycle_id' => 'integer',
            'review_id' => 'integer',
            'from_employment_id' => 'integer',
            'to_employment_id' => 'integer',
            'anonymous' => 'boolean',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
