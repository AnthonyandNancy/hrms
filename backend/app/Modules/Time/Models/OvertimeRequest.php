<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Overtime request fact. `declared_minutes` is what the employee applied for;
 * `confirmed_minutes` is written by a separate confirmation command and must
 * never overwrite the declaration. A confirmed request points at exactly one
 * `overtime_records` row so the existing single-settlement invariant remains
 * the only compensation path.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $employment_id
 * @property \Carbon\CarbonImmutable $business_date
 * @property int $declared_minutes
 * @property int|null $confirmed_minutes
 * @property string $status
 * @property string $reason
 * @property int|null $case_id
 * @property int|null $overtime_record_id
 * @property \Carbon\CarbonImmutable $recorded_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class OvertimeRequest extends Model
{
    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_CONFIRMED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'overtime_requests';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'employment_id',
        'business_date',
        'declared_minutes',
        'confirmed_minutes',
        'status',
        'reason',
        'case_id',
        'overtime_record_id',
        'recorded_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'employment_id' => 'integer',
            'business_date' => 'immutable_date:Y-m-d',
            'declared_minutes' => 'integer',
            'confirmed_minutes' => 'integer',
            'case_id' => 'integer',
            'overtime_record_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
