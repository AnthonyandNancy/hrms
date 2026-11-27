<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $start_local_time
 * @property string $end_local_time
 * @property bool $crosses_midnight
 * @property int $break_minutes
 * @property \Carbon\CarbonImmutable $valid_from
 * @property \Carbon\CarbonImmutable $valid_to
 * @property \Carbon\CarbonImmutable|null $recorded_at
 * @property \Carbon\CarbonImmutable|null $superseded_at
 */
final class ShiftTemplate extends Model
{
    protected $fillable = [
        'code',
        'name',
        'start_local_time',
        'end_local_time',
        'crosses_midnight',
        'break_minutes',
        'late_grace_minutes',
        'early_grace_minutes',
        'standard_hours_decimal',
        'version',
        'valid_from',
        'valid_to',
        'source_type',
        'source_id',
        'recorded_at',
        'superseded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'break_minutes' => 'integer',
            'late_grace_minutes' => 'integer',
            'early_grace_minutes' => 'integer',
            'standard_hours_decimal' => 'string',
            'version' => 'integer',
            'valid_from' => 'immutable_date:Y-m-d',
            'valid_to' => 'immutable_date:Y-m-d',
            'recorded_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }
}
