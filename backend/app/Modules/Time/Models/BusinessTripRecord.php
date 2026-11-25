<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employment_id
 * @property int|null $legal_entity_id
 * @property \Carbon\CarbonImmutable $from_date
 * @property \Carbon\CarbonImmutable $to_date
 * @property string|null $destination
 * @property string|null $purpose
 * @property string $status
 * @property string|null $cancel_reason
 * @property \Carbon\CarbonImmutable|null $cancelled_at
 */
final class BusinessTripRecord extends Model
{
    public const STATUSES = ['DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'CANCELLED', 'COMPLETED'];

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'employment_id',
        'legal_entity_id',
        'from_date',
        'to_date',
        'destination',
        'purpose',
        'status',
        'case_id',
        'cancel_reason',
        'cancelled_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'employment_id' => 'integer',
            'legal_entity_id' => 'integer',
            'from_date' => 'immutable_date:Y-m-d',
            'to_date' => 'immutable_date:Y-m-d',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
