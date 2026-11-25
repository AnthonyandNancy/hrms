<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $version
 * @property array<string, mixed> $config_json
 * @property \Carbon\CarbonImmutable $effective_from
 * @property string $checksum
 * @property \Carbon\CarbonImmutable|null $created_at
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
