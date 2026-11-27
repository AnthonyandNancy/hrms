<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class PerformanceIndicator extends Model
{
    protected $table = 'performance_indicators';

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'category', 'data_source', 'description', 'active', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
