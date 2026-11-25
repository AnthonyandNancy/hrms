<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingCourse extends Model
{
    protected $table = 'training_courses';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'plan_id',
        'code',
        'title',
        'instructor',
        'delivery_mode',
        'duration_minutes',
        'cost_cents',
        'currency',
        'materials',
        'status',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'plan_id' => 'integer',
            'duration_minutes' => 'integer',
            'cost_cents' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
