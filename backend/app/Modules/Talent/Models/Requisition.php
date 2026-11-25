<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class Requisition extends Model
{
    public const STATUSES = ['DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'CLOSED', 'ON_HOLD'];

    protected $table = 'recruitment_requisitions';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'org_unit_id', 'requisition_no', 'title', 'status',
        'headcount', 'budget_minor', 'budget_currency', 'case_id', 'target_start_date', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'legal_entity_id' => 'integer',
            'org_unit_id' => 'integer',
            'headcount' => 'integer',
            'budget_minor' => 'integer',
            'case_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
