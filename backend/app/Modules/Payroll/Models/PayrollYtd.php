<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cumulative year-to-date position. This is the only mutable payroll ledger
 * aggregate: every mutation is performed by PayrollYtdService under a row lock
 * and records the run that caused it.
 */
/**
 * @property int $id
 * @property int $employment_id
 * @property int $tax_year
 * @property int $taxable_income_cents
 * @property int $tax_withheld_cents
 * @property int $social_insurance_cents
 * @property int $housing_fund_cents
 * @property int $gross_cents
 * @property int $net_cents
 * @property int $carry_forward_cents
 * @property int|null $last_run_id
 * @property string $source_type
 * @property int|null $source_id
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class PayrollYtd extends Model
{
    protected $table = 'payroll_ytd';

    /** @var list<string> */
    protected $fillable = [
        'employment_id',
        'tax_year',
        'taxable_income_cents',
        'tax_withheld_cents',
        'social_insurance_cents',
        'housing_fund_cents',
        'gross_cents',
        'net_cents',
        'carry_forward_cents',
        'last_run_id',
        'source_type',
        'source_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_year' => 'integer',
            'taxable_income_cents' => 'integer',
            'tax_withheld_cents' => 'integer',
            'social_insurance_cents' => 'integer',
            'housing_fund_cents' => 'integer',
            'gross_cents' => 'integer',
            'net_cents' => 'integer',
            'carry_forward_cents' => 'integer',
            'last_run_id' => 'integer',
            'updated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
