<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One overtime record may settle exactly once, either as PAY or TIMEOFF. The
 * unique overtime_record_id is the database-level guard against double pay.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $overtime_record_id
 * @property int $employment_id
 * @property string $method
 * @property int $minutes
 * @property string $reason
 * @property \Carbon\CarbonImmutable|null $recorded_at
 */
final class OvertimeSettlement extends Model
{
    protected $table = 'overtime_settlements';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'overtime_record_id',
        'employment_id',
        'method',
        'minutes',
        'payroll_run_id',
        'leave_ledger_entry_id',
        'reason',
        'recorded_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'overtime_record_id' => 'integer',
            'employment_id' => 'integer',
            'minutes' => 'integer',
            'payroll_run_id' => 'integer',
            'leave_ledger_entry_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
