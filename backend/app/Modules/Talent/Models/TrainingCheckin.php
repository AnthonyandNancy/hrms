<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingCheckin extends Model
{
    protected $table = 'training_checkins';

    /** @var list<string> */
    protected $fillable = [
        'session_id',
        'employment_id',
        'checked_in_at',
        'checked_out_at',
        'recorded_by',
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
            'checked_in_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
            'recorded_by' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
