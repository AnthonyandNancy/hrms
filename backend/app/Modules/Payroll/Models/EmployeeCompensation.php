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
 * @property string $effective_from
 * @property string $currency
 * @property int $base_salary_cents
 * @property string|null $salary_scheme_code
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
        'effective_from',
        'currency',
        'base_salary_cents',
        'salary_scheme_code',
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
