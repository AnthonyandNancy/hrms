<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only time-off minutes. Positive entries add credit; negative entries
 * consume, reverse or expire it. There is deliberately no mutable balance row.
 *
 * @property int $id
 * @property int $employment_id
 * @property int|null $overtime_settlement_id
 * @property string $entry_type
 * @property int $quantity_minutes
 * @property \Carbon\CarbonImmutable $effective_on
 * @property string $reason
 * @property int $created_by
 * @property \Carbon\CarbonImmutable|null $recorded_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class TimeoffLedgerEntry extends Model
{
    public const TYPE_EARN = 'EARN';

    public const TYPE_USE = 'USE';

    public const TYPE_REVERSE = 'REVERSE';

    public const TYPE_EXPIRE = 'EXPIRE';

    public const TYPE_ADJUST = 'ADJUST';

    public const TYPES = [
        self::TYPE_EARN,
        self::TYPE_USE,
        self::TYPE_REVERSE,
        self::TYPE_EXPIRE,
        self::TYPE_ADJUST,
    ];

    protected $table = 'timeoff_ledger_entries';

    /** @var list<string> */
    protected $fillable = [
        'employment_id',
        'overtime_settlement_id',
        'entry_type',
        'quantity_minutes',
        'effective_on',
        'reason',
        'created_by',
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
            'employment_id' => 'integer',
            'overtime_settlement_id' => 'integer',
            'quantity_minutes' => 'integer',
            'effective_on' => 'immutable_date:Y-m-d',
            'created_by' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Time-off ledger entries are append-only.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Time-off ledger entries are append-only.');
        });
    }
}
