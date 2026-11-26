<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class PerformanceCycle extends Model
{
    protected $table = 'performance_cycles';

    /** @var list<string> */
    protected $fillable = ['name', 'period_from', 'period_to', 'status', 'recorded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
