<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class PerformanceCalibration extends Model
{
    protected $table = 'performance_calibrations';

    /** @var list<string> */
    protected $fillable = [
        'cycle_id', 'review_id', 'previous_rating',
        'calibrated_rating', 'reason', 'calibrated_by', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cycle_id' => 'integer',
            'review_id' => 'integer',
            'calibrated_by' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
