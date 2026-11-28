<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $month
 * @property string $status
 * @property \Carbon\CarbonImmutable|null $closed_at
 * @property \Carbon\CarbonImmutable|null $reopened_at
 */
final class AttendancePeriodClosure extends Model
{
    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_REOPENED = 'REOPENED';

    protected $table = 'attendance_period_closures';

    /** @var list<string> */
    protected $fillable = [
        'month',
        'status',
        'closed_by',
        'reopened_by',
        'closed_at',
        'reopened_at',
        'reopen_reason',
        'current_fact_version_id',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closed_by' => 'integer',
            'reopened_by' => 'integer',
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'current_fact_version_id' => 'integer',
        ];
    }
}
