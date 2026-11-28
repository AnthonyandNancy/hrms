<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable source fact captured inside a monthly payroll fact version.
 *
 * Rows are never updated or deleted after close. A correction is represented
 * by reopening the period and appending a new version with new rows.
 *
 * @property int $id
 * @property int $fact_version_id
 * @property int $employment_id
 * @property \Carbon\CarbonImmutable $business_date
 * @property \Carbon\CarbonImmutable|null $range_start
 * @property \Carbon\CarbonImmutable|null $range_end
 * @property int $scheduled_minutes
 * @property int $worked_minutes
 * @property int $paid_leave_minutes
 * @property int $partial_leave_minutes
 * @property int $unpaid_leave_minutes
 * @property int $overtime_pay_minutes
 * @property int $overtime_timeoff_minutes
 * @property int $travel_minutes
 * @property int|null $leave_type_id
 * @property int|null $leave_policy_version_id
 * @property string $source_type
 * @property string $source_id
 * @property string|null $source_revision
 * @property string $approval_status
 * @property string $source_hash
 * @property \Carbon\CarbonImmutable $recorded_at
 */
final class TimePayrollFactRow extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'time_payroll_fact_rows';

    /** @var list<string> */
    protected $fillable = [
        'fact_version_id',
        'employment_id',
        'business_date',
        'range_start',
        'range_end',
        'scheduled_minutes',
        'worked_minutes',
        'paid_leave_minutes',
        'partial_leave_minutes',
        'unpaid_leave_minutes',
        'overtime_pay_minutes',
        'overtime_timeoff_minutes',
        'travel_minutes',
        'leave_type_id',
        'leave_policy_version_id',
        'overtime_category',
        'travel_mode',
        'source_type',
        'source_id',
        'source_revision',
        'approval_status',
        'source_hash',
        'recorded_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new DomainException('Time payroll fact rows are append-only.');
        });

        self::deleting(function (): never {
            throw new DomainException('Time payroll fact rows are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fact_version_id' => 'integer',
            'employment_id' => 'integer',
            'business_date' => 'immutable_date:Y-m-d',
            'range_start' => 'immutable_date:Y-m-d',
            'range_end' => 'immutable_date:Y-m-d',
            'scheduled_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'paid_leave_minutes' => 'integer',
            'partial_leave_minutes' => 'integer',
            'unpaid_leave_minutes' => 'integer',
            'overtime_pay_minutes' => 'integer',
            'overtime_timeoff_minutes' => 'integer',
            'travel_minutes' => 'integer',
            'leave_type_id' => 'integer',
            'leave_policy_version_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
