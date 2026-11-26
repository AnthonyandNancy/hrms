<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $employment_id
 * @property \Carbon\CarbonImmutable $business_date
 * @property int $case_id
 * @property array<string, mixed> $corrected_punches_json
 */
final class AttendanceCorrection extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'employment_id',
        'business_date',
        'requested_by',
        'reason',
        'corrected_punches_json',
        'case_id',
        'applied_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date:Y-m-d',
            'corrected_punches_json' => 'array',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
