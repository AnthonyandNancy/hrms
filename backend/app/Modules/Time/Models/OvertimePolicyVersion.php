<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Effective-dated company overtime rule. Publishing appends a version; retiring
 * only stamps the row, so a request snapshot always points at immutable facts.
 *
 * @property int $id
 * @property int $version
 * @property string $status
 * @property \Carbon\CarbonImmutable $effective_from
 * @property \Carbon\CarbonImmutable $effective_to
 * @property int $time_unit_minutes
 * @property string $multiplier
 * @property string $statutory_holiday_multiplier
 * @property bool $allow_pay
 * @property bool $allow_timeoff
 * @property string $allocation
 * @property string $rounding
 * @property string|null $legal_basis
 * @property \Carbon\CarbonImmutable $published_at
 * @property int $published_by
 * @property \Carbon\CarbonImmutable|null $retired_at
 * @property int|null $retired_by
 * @property string|null $retire_reason
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class OvertimePolicyVersion extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_PUBLISHED = 'PUBLISHED';

    public const STATUS_RETIRED = 'RETIRED';

    public const TIME_UNITS = [1, 5, 10, 15, 30, 60];

    public const ALLOCATIONS = [
        'EMPLOYEE_CHOICE',
        'PAY',
        'TIMEOFF',
        'EMPLOYER_DECIDES',
    ];

    protected $table = 'overtime_policy_versions';

    /** @var list<string> */
    protected $fillable = [
        'version',
        'status',
        'effective_from',
        'effective_to',
        'time_unit_minutes',
        'multiplier',
        'statutory_holiday_multiplier',
        'allow_pay',
        'allow_timeoff',
        'allocation',
        'rounding',
        'legal_basis',
        'published_at',
        'published_by',
        'retired_at',
        'retired_by',
        'retire_reason',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'immutable_date:Y-m-d',
            'effective_to' => 'immutable_date:Y-m-d',
            'time_unit_minutes' => 'integer',
            'allow_pay' => 'boolean',
            'allow_timeoff' => 'boolean',
            'published_at' => 'immutable_datetime',
            'published_by' => 'integer',
            'retired_at' => 'immutable_datetime',
            'retired_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
