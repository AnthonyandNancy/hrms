<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Recalculated projection. Rows are replaced on recalculation, but historical
 * rows retain the rule_version that produced them until a new calculation.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $employment_id
 * @property \Carbon\CarbonImmutable $business_date
 * @property string $status
 * @property string $rule_version
 * @property \Carbon\CarbonImmutable|null $calculated_at
 */
final class AttendanceDay extends Model
{
    protected $fillable = [
        'tenant_id',
        'employment_id',
        'business_date',
        'shift_template_id',
        'scheduled_minutes',
        'worked_minutes',
        'overtime_minutes',
        'late_minutes',
        'early_leave_minutes',
        'absence_minutes',
        'status',
        'rule_version',
        'source_hash',
        'calculated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date:Y-m-d',
            'shift_template_id' => 'integer',
            'scheduled_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'absence_minutes' => 'integer',
            'calculated_at' => 'immutable_datetime',
        ];
    }
}
