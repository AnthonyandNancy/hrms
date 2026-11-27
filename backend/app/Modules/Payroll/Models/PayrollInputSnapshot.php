<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable, normalized input evidence. Append-only at model and DB level.
 */
/**
 * @property int $id
 * @property int $run_id
 * @property int $employment_id
 * @property string $snapshot_type
 * @property int $snapshot_version
 * @property array<string, mixed> $payload_json
 * @property string $source_hash
 * @property \Carbon\CarbonImmutable $created_at
 */
final class PayrollInputSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'payroll_input_snapshots';

    /** @var list<string> */
    protected $fillable = [
        'run_id',
        'employment_id',
        'snapshot_type',
        'snapshot_version',
        'payload_json',
        'source_hash',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot_version' => 'integer',
            'payload_json' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \DomainException('Payroll input snapshots are append-only.');
        });

        self::deleting(function (): never {
            throw new \DomainException('Payroll input snapshots are append-only.');
        });
    }
}
