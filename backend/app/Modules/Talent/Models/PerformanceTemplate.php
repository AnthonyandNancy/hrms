<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property array<int, array<string, mixed>> $sections_json
 * @property int $version
 * @property \Carbon\CarbonImmutable $recorded_at
 */
final class PerformanceTemplate extends Model
{
    protected $table = 'performance_templates';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'sections_json', 'version', 'recorded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sections_json' => 'array', 'version' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }
}
