<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Models;

use App\Modules\Workflow\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $case_id
 * @property string $node_key
 * @property int $assignee_user_id
 * @property TaskStatus $status
 * @property \Carbon\CarbonImmutable|null $due_at
 * @property \Carbon\CarbonImmutable|null $acted_at
 */
final class WorkflowTask extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'workflow_tasks';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'case_id',
        'node_key',
        'assignee_user_id',
        'status',
        'due_at',
        'acted_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignee_user_id' => 'integer',
            'status' => TaskStatus::class,
            'due_at' => 'immutable_datetime',
            'acted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CaseModel, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CaseModel::class, 'case_id');
    }
}
