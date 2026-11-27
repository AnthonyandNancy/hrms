<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Calendar-only fact linking an approved trip's date range to an employment.
 * This is not a punch, leave or work-time record; attendance calculation and
 * payroll continue to consume their own evidence.
 *
 * @property int $id
 * @property int $travel_id
 * @property int $employment_id
 * @property \Carbon\CarbonImmutable $from_date
 * @property \Carbon\CarbonImmutable $to_date
 * @property int $source_case_id
 * @property string $status
 * @property \Carbon\CarbonImmutable|null $retired_at
 * @property string|null $retire_reason
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class TravelAttendanceLink extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    protected $table = 'travel_attendance_links';

    /** @var list<string> */
    protected $fillable = [
        'travel_id',
        'employment_id',
        'from_date',
        'to_date',
        'source_case_id',
        'status',
        'retired_at',
        'retire_reason',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'travel_id' => 'integer',
            'employment_id' => 'integer',
            'from_date' => 'immutable_date:Y-m-d',
            'to_date' => 'immutable_date:Y-m-d',
            'source_case_id' => 'integer',
            'retired_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
