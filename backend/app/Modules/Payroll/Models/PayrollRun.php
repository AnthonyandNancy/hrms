<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Enums\PayrollRunType;
use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payroll run aggregate. State transitions are owned by PayrollStateMachine;
 * model hooks are the last line of defence against mutating sealed facts.
 */
final class PayrollRun extends Model
{
    use BelongsToTenant;

    protected $table = 'payroll_runs';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'scheme_id',
        'period',
        'earning_period_start',
        'earning_period_end',
        'posting_period',
        'pay_date',
        'cutoff_at',
        'status',
        'run_type',
        'correction_of_run_id',
        'prepared_by',
        'reviewed_by',
        'approved_by',
        'published_by',
        'paid_by',
        'gross_cents',
        'deductions_cents',
        'net_cents',
        'employer_cost_cents',
        'input_hash',
        'rule_version',
        'snapshot_version',
        'reopen_count',
        'reopen_approvals_json',
        'reopened_at',
        'approval_case_id',
        'approval_effect_status',
        'cancellation_reason',
        'bank_export_hash',
        'tax_export_hash',
        'export_generated_at',
        'calculated_at',
        'reviewed_at',
        'approved_at',
        'published_at',
        'paid_at',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayrollRunStatus::class,
            'run_type' => PayrollRunType::class,
            'scheme_id' => 'integer',
            'correction_of_run_id' => 'integer',
            'prepared_by' => 'integer',
            'reviewed_by' => 'integer',
            'approved_by' => 'integer',
            'published_by' => 'integer',
            'paid_by' => 'integer',
            'gross_cents' => 'integer',
            'deductions_cents' => 'integer',
            'net_cents' => 'integer',
            'employer_cost_cents' => 'integer',
            'snapshot_version' => 'integer',
            'reopen_count' => 'integer',
            'reopen_approvals_json' => 'array',
            'reopened_at' => 'immutable_datetime',
            'approval_case_id' => 'integer',
            'export_generated_at' => 'immutable_datetime',
            'calculated_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'earning_period_start' => 'immutable_date:Y-m-d',
            'earning_period_end' => 'immutable_date:Y-m-d',
            'pay_date' => 'immutable_date:Y-m-d',
            'cutoff_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<PayrollRunEmployee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(PayrollRunEmployee::class, 'run_id')->orderBy('employment_id');
    }

    /**
     * @return HasMany<PayrollInputSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(PayrollInputSnapshot::class, 'run_id');
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'run_id')->orderBy('employment_id');
    }

    protected static function booted(): void
    {
        self::updating(function (PayrollRun $run): void {
            $original = $run->getOriginal('status');
            $original = $original instanceof PayrollRunStatus ? $original : PayrollRunStatus::from((string) $original);

            if ($original === PayrollRunStatus::CLOSED) {
                throw new \DomainException('Closed payroll runs are immutable.');
            }

            if (in_array($original, [PayrollRunStatus::PUBLISHED, PayrollRunStatus::PAID], true)) {
                $sealed = [
                    'gross_cents',
                    'deductions_cents',
                    'net_cents',
                    'employer_cost_cents',
                    'input_hash',
                    'period',
                    'scheme_id',
                    'cutoff_at',
                    'correction_of_run_id',
                    'legal_entity_id',
                    'run_type',
                ];

                $changedSealed = array_values(array_intersect(array_keys($run->getDirty()), $sealed));

                if ($changedSealed !== []) {
                    throw new \DomainException('Published payroll financial facts are immutable.');
                }
            }
        });

        self::deleting(function (PayrollRun $run): never {
            $status = $run->status instanceof PayrollRunStatus
                ? $run->status
                : PayrollRunStatus::from((string) $run->status);

            if ($status->isSealed()) {
                throw new \DomainException('Sealed payroll runs cannot be deleted.');
            }

            throw new \DomainException('Payroll runs are never deleted; cancel a draft instead.');
        });
    }
}
