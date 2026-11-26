<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class Interview extends Model
{
    protected $table = 'interviews';

    /** @var list<string> */
    protected $fillable = [
        'candidate_id', 'requisition_id', 'scheduled_at', 'duration_minutes',
        'interviewer', 'mode', 'status', 'recommendation', 'feedback', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'candidate_id' => 'integer',
            'requisition_id' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
