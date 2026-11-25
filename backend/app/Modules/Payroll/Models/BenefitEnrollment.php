<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only statutory base movement for one employment. A base change opens
 * a new version row; historical rows are never rewritten.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $legal_entity_id
 * @property int $employment_id
 * @property int $plan_id
 * @property int $base_amount_cents
 * @property string $effective_from
 * @property string|null $effective_to
 * @property int $version
 * @property int $created_by
 */
final class BenefitEnrollment extends Model
{
    public $timestamps = false;

    protected $table = 'benefit_enrollments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'employment_id',
        'plan_id',
        'base_amount_cents',
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
            'employment_id' => 'integer',
            'plan_id' => 'integer',
            'base_amount_cents' => 'integer',
            'version' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
