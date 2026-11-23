<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property int $version
 * @property string $status
 * @property array<string, mixed> $definition_json
 * @property array<string, mixed>|null $definition
 * @property \Carbon\CarbonImmutable $created_at
 */
final class WorkflowTemplate extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'workflow_templates';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'version',
        'status',
        'definition_json',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'definition_json' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
