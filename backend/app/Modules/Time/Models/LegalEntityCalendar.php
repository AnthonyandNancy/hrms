<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $legal_entity_id
 * @property string $timezone
 * @property list<int> $workweek_json
 * @property int $standard_day_minutes
 */
final class LegalEntityCalendar extends Model
{
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'timezone',
        'workweek_json',
        'standard_day_minutes',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workweek_json' => 'array',
            'standard_day_minutes' => 'integer',
            'version' => 'integer',
        ];
    }
}
