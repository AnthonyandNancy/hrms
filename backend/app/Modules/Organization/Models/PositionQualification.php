<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Versioned qualification requirements for a position.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $position_id
 * @property int $version
 * @property array<string, mixed> $requirements_json
 * @property string $effective_from
 */
final class PositionQualification extends Model
{
    protected $table = 'position_qualifications';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'position_id',
        'version',
        'requirements_json',
        'effective_from',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'position_id' => 'integer',
            'version' => 'integer',
            'requirements_json' => 'array',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
