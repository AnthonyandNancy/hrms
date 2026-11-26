<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class PerformanceAppeal extends Model
{
    protected $table = 'performance_appeals';

    /** @var list<string> */
    protected $fillable = ['review_id', 'case_id', 'reason', 'status', 'recorded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['review_id' => 'integer', 'case_id' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
