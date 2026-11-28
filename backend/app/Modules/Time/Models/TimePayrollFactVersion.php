<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable monthly Time payroll fact version.
 *
 * A version is generated once on close. Reopening changes only the lifecycle
 * evidence; period, version number, hash and precheck payload can never be
 * rewritten. A subsequent close appends a new version.
 *
 * @property int $id
 * @property string $period
 * @property int $version
 * @property string $status
 * @property string|null $source_hash
 * @property int $exception_count
 * @property array<string, mixed>|null $precheck_json
 * @property \Carbon\CarbonImmutable $generated_at
 * @property \Carbon\CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property \Carbon\CarbonImmutable|null $reopened_at
 * @property int|null $reopened_by
 * @property string|null $reopen_reason
 * @property int|null $reopened_from_version_id
 */
final class TimePayrollFactVersion extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSING = 'CLOSING';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_REOPENED = 'REOPENED';

    protected $table = 'time_payroll_fact_versions';

    /** @var list<string> */
    protected $fillable = [
        'period',
        'version',
        'status',
        'source_hash',
        'exception_count',
        'precheck_json',
        'generated_at',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
        'reopened_from_version_id',
        'created_at',
        'updated_at',
    ];

    protected static function booted(): void
    {
        self::updating(function (TimePayrollFactVersion $version): void {
            $from = (string) $version->getOriginal('status');
            $to = (string) $version->status;

            if ($from === self::STATUS_CLOSING) {
                $allowed = [
                    'status',
                    'source_hash',
                    'exception_count',
                    'precheck_json',
                    'closed_at',
                    'closed_by',
                    'updated_at',
                ];

                if ($to !== self::STATUS_CLOSING && $to !== self::STATUS_CLOSED) {
                    throw new DomainException('Unsupported Time payroll fact version transition.');
                }

                if ($to === self::STATUS_CLOSED && array_diff(array_keys($version->getDirty()), $allowed) !== []) {
                    throw new DomainException('Unsupported Time payroll fact version transition.');
                }

                return;
            }

            if ($from === self::STATUS_CLOSED) {
                $allowed = ['status', 'reopened_at', 'reopened_by', 'reopen_reason', 'updated_at'];

                if ($to !== self::STATUS_REOPENED || array_diff(array_keys($version->getDirty()), $allowed) !== []) {
                    throw new DomainException('Unsupported Time payroll fact version transition.');
                }

                return;
            }

            throw new DomainException('Time payroll fact versions are immutable.');
        });

        self::deleting(function (): never {
            throw new DomainException('Time payroll fact versions are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'exception_count' => 'integer',
            'precheck_json' => 'array',
            'generated_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'closed_by' => 'integer',
            'reopened_at' => 'immutable_datetime',
            'reopened_by' => 'integer',
            'reopened_from_version_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
