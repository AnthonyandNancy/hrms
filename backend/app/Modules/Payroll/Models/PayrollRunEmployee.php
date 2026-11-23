<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\PayrollEmployeeStatus;
use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $run_id
 * @property int $employment_id
 * @property PayrollEmployeeStatus $status
 * @property int $gross_cents
 * @property int $taxable_cents
 * @property int $deductions_cents
 * @property int $net_cents
 * @property int $employer_cost_cents
 * @property int $employee_si_cents
 * @property int $employee_hf_cents
 * @property int $employer_si_cents
 * @property int $employer_hf_cents
 * @property int $tax_cents
 * @property int $ytd_taxable_opening_cents
 * @property int $ytd_tax_withheld_opening_cents
 * @property bool $ytd_applied
 * @property \Carbon\CarbonImmutable|null $ytd_applied_at
 * @property string|null $failure_reason
 */
final class PayrollRunEmployee extends Model
{
    use BelongsToTenant;

    protected $table = 'payroll_run_employees';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'run_id',
        'employment_id',
        'status',
        'gross_cents',
        'taxable_cents',
        'deductions_cents',
        'net_cents',
        'employer_cost_cents',
        'employee_si_cents',
        'employee_hf_cents',
        'employer_si_cents',
        'employer_hf_cents',
        'tax_cents',
        'ytd_taxable_opening_cents',
        'ytd_tax_withheld_opening_cents',
        'ytd_applied',
        'ytd_applied_at',
        'failure_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayrollEmployeeStatus::class,
            'gross_cents' => 'integer',
            'taxable_cents' => 'integer',
            'deductions_cents' => 'integer',
            'net_cents' => 'integer',
            'employer_cost_cents' => 'integer',
            'employee_si_cents' => 'integer',
            'employee_hf_cents' => 'integer',
            'employer_si_cents' => 'integer',
            'employer_hf_cents' => 'integer',
            'tax_cents' => 'integer',
            'ytd_taxable_opening_cents' => 'integer',
            'ytd_tax_withheld_opening_cents' => 'integer',
            'ytd_applied' => 'boolean',
            'ytd_applied_at' => 'immutable_datetime',
        ];
    }
}
