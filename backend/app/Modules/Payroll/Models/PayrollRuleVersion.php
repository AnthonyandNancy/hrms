<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $scheme_item_id
 * @property int $version
 * @property array<string, mixed> $ast_json
 * @property string $stage
 * @property array<string, mixed>|null $dependencies_json
 * @property string $rounding_mode
 * @property string $unit
 * @property string $engine_version
 * @property string $hash
 * @property string $status
 * @property int $created_by
 * @property int|null $published_by
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property string|null $retire_reason
 */
final class PayrollRuleVersion extends Model
{
    protected $table = 'payroll_rule_versions';

    /** @var list<string> */
    protected $fillable = [
        'scheme_item_id',
        'version',
        'ast_json',
        'stage',
        'dependencies_json',
        'rounding_mode',
        'unit',
        'engine_version',
        'hash',
        'status',
        'created_by',
        'published_by',
        'published_at',
        'retire_reason',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheme_item_id' => 'integer',
            'version' => 'integer',
            'ast_json' => 'array',
            'dependencies_json' => 'array',
            'created_by' => 'integer',
            'published_by' => 'integer',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
