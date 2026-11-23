<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only leave ledger. Canonical quantity is signed integer minutes for
 * every leave type; day display divides by the legal entity standard day.
 * Signed quantities follow the account direction:
 * GRANT/RELEASE/REVERSE are positive, RESERVE/CONSUME/EXPIRE negative, and
 * ADJUST may be either sign. See docs/07-business-rules-time.md.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $employment_id
 * @property int $leave_type_id
 * @property string $account
 * @property int $quantity_minutes
 * @property string $business_date
 * @property string $effective_on
 * @property string $source_type
 * @property string $source_id
 * @property int|null $case_id
 * @property string $reason
 * @property string $entry_hash
 * @property \Carbon\CarbonImmutable|null $recorded_at
 */
final class LeaveLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'employment_id',
        'leave_type_id',
        'account',
        'quantity_minutes',
        'business_date',
        'effective_on',
        'source_type',
        'source_id',
        'case_id',
        'reason',
        'entry_hash',
        'recorded_at',
        'created_at',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new DomainException('Leave ledger entries are append-only.');
        });

        self::deleting(function (): never {
            throw new DomainException('Leave ledger entries are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_minutes' => 'integer',
            'business_date' => 'immutable_date:Y-m-d',
            'effective_on' => 'immutable_date:Y-m-d',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
