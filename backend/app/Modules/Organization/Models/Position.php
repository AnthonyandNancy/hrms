<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $legal_entity_id
 * @property int|null $org_unit_id
 * @property string $code
 * @property string $title
 * @property string $status
 * @property int|null $headcount_limit
 */
final class Position extends Model
{
    protected $table = 'positions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'org_unit_id',
        'code',
        'title',
        'grade',
        'status',
        'headcount_limit',
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
            'org_unit_id' => 'integer',
            'headcount_limit' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }
}
