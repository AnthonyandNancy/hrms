<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rehearsal-only target. It is explicitly labelled SHADOW in every report and
 * never masquerades as imported business data.
 *
 * @property int $id
 * @property array<string, mixed> $payload_json
 */
final class MigrationShadowRow extends Model
{
    protected $table = 'migration_shadow_rows';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'domain',
        'natural_key',
        'migration_run_id',
        'source_id',
        'data_hash',
        'payload_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'source_id' => 'integer',
            'migration_run_id' => 'integer',
        ];
    }
}
