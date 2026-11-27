<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Models;

use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Enums\MigrationRunMode;
use App\Modules\Platform\Migration\Enums\MigrationRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property MigrationDomain $domain
 * @property MigrationRunMode $mode
 * @property MigrationRunStatus $status
 * @property array<string, mixed>|null $report_json
 */
final class MigrationRun extends Model
{
    protected $table = 'migration_runs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'domain',
        'source_file',
        'source_file_hash',
        'mapping_hash',
        'mode',
        'status',
        'rows_read',
        'rows_valid',
        'rows_created',
        'rows_updated',
        'rows_skipped',
        'rows_failed',
        'started_at',
        'finished_at',
        'report_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domain' => MigrationDomain::class,
            'mode' => MigrationRunMode::class,
            'status' => MigrationRunStatus::class,
            'rows_read' => 'integer',
            'rows_valid' => 'integer',
            'rows_created' => 'integer',
            'rows_updated' => 'integer',
            'rows_skipped' => 'integer',
            'rows_failed' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'report_json' => 'array',
        ];
    }

    /**
     * @return HasMany<MigrationIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(MigrationIssue::class, 'run_id');
    }
}
