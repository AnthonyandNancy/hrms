<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $legal_entity_id
 * @property \Carbon\CarbonImmutable $date
 * @property string $type
 */
final class Holiday extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_PUBLIC = 'PUBLIC';

    public const TYPE_COMPANY = 'COMPANY';

    public const TYPE_MAKEUP = 'MAKEUP';

    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'date',
        'name',
        'type',
        'source',
        'version',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
            'version' => 'integer',
        ];
    }
}
