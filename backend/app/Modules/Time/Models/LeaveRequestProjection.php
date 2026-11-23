<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property \Carbon\CarbonImmutable $from_date
 * @property \Carbon\CarbonImmutable $to_date
 */
final class LeaveRequestProjection extends Model
{
    protected $fillable = [
        'tenant_id',
        'employment_id',
        'leave_type_id',
        'case_id',
        'from_date',
        'to_date',
        'minutes',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_date' => 'immutable_date:Y-m-d',
            'to_date' => 'immutable_date:Y-m-d',
            'minutes' => 'integer',
        ];
    }
}
