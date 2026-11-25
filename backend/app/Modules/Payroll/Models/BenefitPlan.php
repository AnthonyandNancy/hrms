<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Effective-dated statutory benefit plan version. Never update an old version
 * to change a rate: create the next version instead.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $legal_entity_id
 * @property string $code
 * @property string $name
 * @property string $employer_rate
 * @property string $employee_rate
 * @property string $effective_from
 * @property string|null $effective_to
 * @property int $version
 * @property int $created_by
 */
final class BenefitPlan extends Model
{
    public $timestamps = false;

    protected $table = 'benefit_plans';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'code',
        'name',
        'employer_rate',
        'employee_rate',
        'effective_from',
        'effective_to',
        'version',
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
            'version' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
