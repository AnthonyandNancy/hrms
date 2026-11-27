<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingExam extends Model
{
    protected $table = 'training_exams';

    /** @var list<string> */
    protected $fillable = [
        'course_id',
        'code',
        'title',
        'version',
        'passing_score',
        'max_score',
        'status',
        'published_at',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'version' => 'integer',
            'passing_score' => 'integer',
            'max_score' => 'integer',
            'published_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
