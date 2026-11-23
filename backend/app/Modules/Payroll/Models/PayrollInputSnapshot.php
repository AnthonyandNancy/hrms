<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable, normalized input evidence. Append-only at model and DB level.
 */
final class PayrollInputSnapshot extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'payroll_input_snapshots';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
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
