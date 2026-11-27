<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Attendance capture device inventory and health.
 *
 * Devices are read-only through the API: health is reported by the adapter
 * pipeline, never set by a browser. The list endpoint therefore only issues
 * SELECTs against real rows.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $adapter
 * @property string $health
 * @property \Carbon\CarbonImmutable|null $last_seen_at
 * @property bool $active
 */
final class AttendanceDevice extends Model
{
    public const HEALTH_ONLINE = 'ONLINE';

    public const HEALTH_DEGRADED = 'DEGRADED';

    public const HEALTH_OFFLINE = 'OFFLINE';

    public const HEALTH_UNKNOWN = 'UNKNOWN';

    /**
     * Health values the API contract exposes.
     *
     * @var list<string>
     */
    public const HEALTH_VALUES = [
        self::HEALTH_ONLINE,
        self::HEALTH_DEGRADED,
        self::HEALTH_OFFLINE,
        self::HEALTH_UNKNOWN,
    ];

    protected $table = 'attendance_devices';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'adapter',
        'health',
        'last_seen_at',
        'active',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'health' => 'string',
            'last_seen_at' => 'immutable_datetime',
            'active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
