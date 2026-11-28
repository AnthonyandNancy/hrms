<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Enums\PayrollRunType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payroll run aggregate. State transitions are owned by PayrollStateMachine;
 * model hooks are the last line of defence against mutating sealed facts.
 */
/**
 * @property int $id
 * @property int $scheme_id
 * @property string $period
 * @property string $earning_period_start
 * @property string $earning_period_end
 * @property string $posting_period
 * @property string $pay_date
 * @property \Carbon\CarbonImmutable $cutoff_at
 * @property PayrollRunStatus $status
 * @property PayrollRunType $run_type
 * @property int|null $correction_of_run_id
 * @property int|null $correction_baseline_run_id
 * @property int $correction_sequence
 * @property string $correction_status
 * @property \Carbon\CarbonImmutable|null $correction_voided_at
 * @property string|null $correction_void_reason
 * @property array<int, int>|null $prior_correction_ids_json
 * @property int|null $correction_target_net_cents
 * @property int|null $correction_baseline_net_cents
 * @property int|null $correction_delta_net_cents
 * @property int|null $correction_cumulative_net_cents
 * @property int $prepared_by
 * @property int|null $reviewed_by
 * @property int|null $approved_by
 * @property int|null $published_by
 * @property int|null $paid_by
 * @property int $gross_cents
 * @property int $deductions_cents
 * @property int $net_cents
 * @property int $employer_cost_cents
 * @property string|null $input_hash
 * @property string|null $rule_version
 * @property int $snapshot_version
 * @property int $reopen_count
 * @property array<int, array<string, mixed>>|null $reopen_approvals_json
 * @property \Carbon\CarbonImmutable|null $reopened_at
 * @property int|null $approval_case_id
 * @property string|null $approval_effect_status
 * @property string|null $cancellation_reason
 * @property string|null $bank_export_hash
 * @property string|null $tax_export_hash
 * @property \Carbon\CarbonImmutable|null $export_generated_at
 * @property \Carbon\CarbonImmutable|null $calculated_at
 * @property \Carbon\CarbonImmutable|null $reviewed_at
 * @property \Carbon\CarbonImmutable|null $approved_at
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property \Carbon\CarbonImmutable|null $paid_at
 * @property string $payslip_status
 * @property string $disbursement_status
 * @property string|null $disbursement_reference
 * @property int|null $disbursement_registered_by
 * @property \Carbon\CarbonImmutable|null $disbursement_registered_at
 * @property \Carbon\CarbonImmutable|null $closed_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class PayrollRun extends Model
{
    protected $table = 'payroll_runs';

    /** @var list<string> */
    protected $fillable = [
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
        'correction_baseline_run_id',
        'correction_sequence',
        'correction_status',
        'correction_voided_at',
        'correction_void_reason',
        'prior_correction_ids_json',
        'correction_target_net_cents',
        'correction_baseline_net_cents',
        'correction_delta_net_cents',
        'correction_cumulative_net_cents',
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
        'payslip_status',
        'disbursement_status',
        'disbursement_reference',
        'disbursement_registered_by',
        'disbursement_registered_at',
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
            'correction_baseline_run_id' => 'integer',
            'correction_sequence' => 'integer',
            'correction_voided_at' => 'immutable_datetime',
            'prior_correction_ids_json' => 'array',
            'correction_target_net_cents' => 'integer',
            'correction_baseline_net_cents' => 'integer',
            'correction_delta_net_cents' => 'integer',
            'correction_cumulative_net_cents' => 'integer',
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
            'disbursement_registered_by' => 'integer',
            'disbursement_registered_at' => 'immutable_datetime',
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
