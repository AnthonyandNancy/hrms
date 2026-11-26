<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One employment scheduled to one shift template on one business date.
 *
 * The (tenant, employment, business_date) unique index is the database
 * invariant behind the service's SCHEDULE_CONFLICT check.
 *
 * @property int $id
 * @property int $employment_id
 * @property \Carbon\CarbonImmutable $business_date
 * @property int $shift_template_id
 * @property int $created_by
 */
final class ShiftSchedule extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'shift_schedules';

    /** @var list<string> */
    protected $fillable = [
        'employment_id',
        'business_date',
        'shift_template_id',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employment_id' => 'integer',
            'business_date' => 'immutable_date:Y-m-d',
            'shift_template_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
