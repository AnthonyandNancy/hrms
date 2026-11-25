<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Salary adjustment evidence. It may only be created from an approved
 * Workflow case and points at the compensation version it produced.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $legal_entity_id
 * @property int $employment_id
 * @property string $effective_from
 * @property string $currency
 * @property int $base_salary_cents
 * @property string $reason
 * @property int $case_id
 * @property int $compensation_id
 * @property int $created_by
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class SalaryAdjustment extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'salary_adjustments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'employment_id',
        'effective_from',
        'currency',
        'base_salary_cents',
        'reason',
        'case_id',
        'compensation_id',
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
            'base_salary_cents' => 'integer',
            'case_id' => 'integer',
            'compensation_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \DomainException('Salary adjustments are append-only.');
        });

        self::deleting(function (): never {
            throw new \DomainException('Salary adjustments are append-only.');
        });
    }
}
