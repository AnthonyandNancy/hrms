<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Models;

use App\Modules\Platform\Migration\Enums\IssueSeverity;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $run_id
 * @property int|null $stg_id
 * @property IssueSeverity $severity
 * @property string $code
 * @property string $message
 */
final class MigrationIssue extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'migration_issues';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'run_id',
        'stg_id',
        'severity',
        'code',
        'message',
        'source_row_number',
        'field',
        'resolved_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => IssueSeverity::class,
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
