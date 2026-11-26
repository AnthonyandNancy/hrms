<?php

declare(strict_types=1);

namespace App\Modules\Platform\Retention;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $status
 * @property array<string, mixed>|null $report_json
 */
final class RetentionPurgeRun extends Model
{
    protected $table = 'retention_purge_runs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'retention_class',
        'mode',
        'status',
        'candidates',
        'held',
        'purged',
        'report_json',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_json' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
