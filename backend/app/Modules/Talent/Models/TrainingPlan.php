<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingPlan extends Model
{
    protected $table = 'training_plans';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'title',
        'year',
        'budget_cents',
        'currency',
        'status',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'budget_cents' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
