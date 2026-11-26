<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class CandidateEvaluation extends Model
{
    protected $table = 'candidate_evaluations';

    /** @var list<string> */
    protected $fillable = ['candidate_id', 'interview_id', 'evaluator_user_id', 'rating', 'recommendation', 'strengths', 'concerns', 'comments', 'status', 'recorded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'candidate_id' => 'integer',
            'interview_id' => 'integer',
            'evaluator_user_id' => 'integer',
            'rating' => 'integer',
        ];
    }
}
