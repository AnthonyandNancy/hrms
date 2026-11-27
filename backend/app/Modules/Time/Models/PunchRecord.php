<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Raw device/import punches are evidence and can never be edited or deleted.
 * Corrections are separate Case-linked rows and attendance is recalculated.
 *
 * @property int $id
 * @property int $employment_id
 * @property \Carbon\CarbonImmutable $punched_at_utc
 * @property string $source
 * @property string $raw_payload_hash
 */
final class PunchRecord extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'employment_id',
        'device_id',
        'punched_at_utc',
        'source_local_offset',
        'source',
        'raw_payload_hash',
        'created_at',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new DomainException('Punch records are immutable.');
        });

        self::deleting(function (): never {
            throw new DomainException('Punch records are immutable.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'punched_at_utc' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
