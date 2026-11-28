<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Effective-dated employee compensation version. Rows are append-only: a new
 * effective date creates a new version and never rewrites an older one.
 *
 * @property int $id
 * @property int $employment_id
 * @property int|null $contract_version_id
 * @property string $effective_from
 * @property string|null $effective_to
 * @property string $status
 * @property string $currency
 * @property int $base_salary_cents
 * @property string|null $salary_scheme_code
 * @property int|null $payroll_scheme_id
 * @property int|null $payroll_scheme_version
 * @property array<string, mixed>|null $parameter_overrides_json
 * @property int|null $approved_case_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $content_hash
 * @property int|null $supersedes_id
 * @property int $created_by
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class EmployeeCompensation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'employee_compensations';

    /** @var list<string> */
    protected $fillable = [
        'employment_id',
        'contract_version_id',
        'effective_from',
        'effective_to',
        'status',
        'currency',
        'base_salary_cents',
        'salary_scheme_code',
        'payroll_scheme_id',
        'payroll_scheme_version',
        'parameter_overrides_json',
        'approved_case_id',
        'source_type',
        'source_id',
        'content_hash',
        'supersedes_id',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_id' => 'integer',
            'contract_version_id' => 'integer',
            'payroll_scheme_id' => 'integer',
            'payroll_scheme_version' => 'integer',
            'parameter_overrides_json' => 'array',
            'approved_case_id' => 'integer',
            'source_id' => 'integer',
            'supersedes_id' => 'integer',
            'base_salary_cents' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \DomainException('Compensation versions are append-only.');
        });

        self::deleting(function (): never {
            throw new \DomainException('Compensation versions are append-only.');
        });
    }
}
