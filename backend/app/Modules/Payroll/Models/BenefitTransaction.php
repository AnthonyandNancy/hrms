<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Monthly benefit cost ledger row. A row may reference a payroll run but the
 * same enrollment/period and payroll run cannot be paid twice.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $legal_entity_id
 * @property int $employment_id
 * @property int $enrollment_id
 * @property string $period
 * @property int $base_amount_cents
 * @property int $employee_amount_cents
 * @property int $employer_amount_cents
 * @property int $total_amount_cents
 * @property int|null $payroll_run_id
 * @property string $status
 * @property int $created_by
 */
final class BenefitTransaction extends Model
{
    public $timestamps = false;

    protected $table = 'benefit_transactions';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'employment_id',
        'enrollment_id',
        'period',
        'base_amount_cents',
        'employee_amount_cents',
        'employer_amount_cents',
        'total_amount_cents',
        'payroll_run_id',
        'status',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'legal_entity_id' => 'integer',
            'employment_id' => 'integer',
            'enrollment_id' => 'integer',
            'base_amount_cents' => 'integer',
            'employee_amount_cents' => 'integer',
            'employer_amount_cents' => 'integer',
            'total_amount_cents' => 'integer',
            'payroll_run_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
