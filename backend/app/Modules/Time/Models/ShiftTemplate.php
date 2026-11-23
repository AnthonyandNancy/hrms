<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $legal_entity_id
 * @property string $code
 * @property string $start_local_time
 * @property string $end_local_time
 * @property bool $crosses_midnight
 * @property int $break_minutes
 */
final class ShiftTemplate extends Model
{
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
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
        ];
    }
}
