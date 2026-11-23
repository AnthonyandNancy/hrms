<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $legal_entity_id
 * @property int|null $parent_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string $status
 * @property string $valid_from
 * @property string $valid_to
 */
final class OrgUnit extends Model
{
    public const TYPES = ['COMPANY', 'DIVISION', 'DEPARTMENT', 'TEAM', 'COST_CENTER', 'SHARED_SERVICE'];

    protected $table = 'org_units';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'parent_id',
        'code',
        'name',
        'type',
        'status',
        'valid_from',
        'valid_to',
        'recorded_at',
        'superseded_at',
        'source_type',
        'source_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'legal_entity_id' => 'integer',
            'parent_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
