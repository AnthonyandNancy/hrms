<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $employment_id
 * @property int $tax_year
 * @property int $source_run_id
 * @property int $source_employee_id
 * @property string $source_type
 * @property string $entry_type
 * @property int $taxable_income_cents
 * @property int $tax_withheld_cents
 * @property int $social_insurance_cents
 * @property int $housing_fund_cents
 * @property int $gross_cents
 * @property int $net_cents
 * @property int $carry_forward_cents
 * @property string $source_hash
 * @property \Carbon\CarbonImmutable $created_at
 */
final class PayrollYtdLedgerEntry extends Model
{
    protected $table = 'payroll_ytd_ledger_entries';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'employment_id',
        'tax_year',
        'source_run_id',
        'source_employee_id',
        'source_type',
        'entry_type',
        'taxable_income_cents',
        'tax_withheld_cents',
        'social_insurance_cents',
        'housing_fund_cents',
        'gross_cents',
        'net_cents',
        'carry_forward_cents',
        'source_hash',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_id' => 'integer',
            'tax_year' => 'integer',
            'source_run_id' => 'integer',
            'source_employee_id' => 'integer',
            'taxable_income_cents' => 'integer',
            'tax_withheld_cents' => 'integer',
            'social_insurance_cents' => 'integer',
            'housing_fund_cents' => 'integer',
            'gross_cents' => 'integer',
            'net_cents' => 'integer',
            'carry_forward_cents' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
