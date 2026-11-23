<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $version
 * @property array<string, mixed> $config_json
 */
final class AttendanceRuleVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'version',
        'config_json',
        'effective_from',
        'checksum',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config_json' => 'array',
            'effective_from' => 'immutable_date:Y-m-d',
        ];
    }
}
