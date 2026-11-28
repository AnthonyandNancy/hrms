<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $exam_id
 * @property int|null $session_id
 * @property int $enrollment_id
 * @property int $employment_id
 * @property int $attempt_no
 * @property int $score
 * @property bool $passed
 * @property array<string, mixed>|null $answers_json
 * @property string $source_hash
 * @property \Carbon\CarbonImmutable $submitted_at
 * @property int $recorded_by
 * @property \Carbon\CarbonImmutable $created_at
 */
final class TrainingExamAttempt extends Model
{
    protected $table = 'training_exam_attempts';

    /** @var list<string> */
    protected $fillable = [
        'exam_id',
        'session_id',
        'enrollment_id',
        'employment_id',
        'attempt_no',
        'score',
        'passed',
        'answers_json',
        'source_hash',
        'submitted_at',
        'recorded_by',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exam_id' => 'integer',
            'session_id' => 'integer',
            'enrollment_id' => 'integer',
            'employment_id' => 'integer',
            'attempt_no' => 'integer',
            'score' => 'integer',
            'passed' => 'boolean',
            'answers_json' => 'array',
            'submitted_at' => 'immutable_datetime',
            'recorded_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
