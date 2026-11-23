<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $case_id
 * @property int|null $employment_id
 * @property string $task_code
 * @property string $title
 * @property string $status
 * @property bool $required
 * @property string|null $assignee_role
 * @property string|null $waiver_reason
 * @property string|null $due_date
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property int|null $completed_by_user_id
 */
final class LifecycleTask extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_DONE = 'DONE';

    public const STATUS_WAIVED = 'WAIVED';

    protected $table = 'lifecycle_tasks';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'case_id',
        'employment_id',
        'task_code',
        'title',
        'status',
        'required',
        'assignee_role',
        'waiver_reason',
        'due_date',
        'completed_at',
        'completed_by_user_id',
        'recorded_at',
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
            'case_id' => 'integer',
            'employment_id' => 'integer',
            'required' => 'boolean',
            'completed_at' => 'immutable_datetime',
            'completed_by_user_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
