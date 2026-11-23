<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $tenant_id
 * @property int $ancestor_id
 * @property int $descendant_id
 * @property int $depth
 * @property string $valid_from
 * @property string $valid_to
 */
final class OrgUnitClosure extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'org_unit_closure';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'ancestor_id',
        'descendant_id',
        'depth',
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
            'ancestor_id' => 'integer',
            'descendant_id' => 'integer',
            'depth' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }
}
