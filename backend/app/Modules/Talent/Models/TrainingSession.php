<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingSession extends Model
{
    protected $table = 'training_sessions';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'program_id', 'starts_on', 'ends_on', 'starts_at', 'ends_at',
        'capacity', 'location', 'status', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'program_id' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
