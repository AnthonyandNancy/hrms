<?php

declare(strict_types=1);

namespace App\Modules\IAM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Synced read model of the code-owned catalog. No API writes this table.
 *
 * @property string $code
 * @property list<string> $actions_json
 * @property list<string> $anchor_types_json
 */
final class Capability extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'capabilities';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'resource',
        'actions_json',
        'anchor_types_json',
        'field_profile_max',
        'risk',
        'sod_group',
        'description',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actions_json' => 'array',
            'anchor_types_json' => 'array',
            'synced_at' => 'immutable_datetime',
        ];
    }
}
