<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingFeedback extends Model
{
    protected $table = 'training_feedback';

    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'session_id',
        'employment_id',
        'rating',
        'comments',
        'questionnaire_json',
        'status',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrollment_id' => 'integer',
            'session_id' => 'integer',
            'employment_id' => 'integer',
            'rating' => 'integer',
            'questionnaire_json' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
