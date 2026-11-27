<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingEnrollment extends Model
{
    protected $table = 'training_enrollments';

    /** @var list<string> */
    protected $fillable = [
        'session_id',
        'employment_id',
        'status',
        'attended',
        'completion_result',
        'score',
        'completed_at',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'session_id' => 'integer',
            'employment_id' => 'integer',
            'attended' => 'boolean',
            'score' => 'integer',
            'completed_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
