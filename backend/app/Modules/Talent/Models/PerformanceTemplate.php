<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

final class PerformanceTemplate extends Model
{
    protected $table = 'performance_templates';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'code', 'name', 'sections_json', 'version', 'recorded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'sections_json' => 'array', 'version' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
