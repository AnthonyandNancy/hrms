<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Models;

use App\Modules\Workflow\Enums\InstanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $case_id
 * @property string $current_node_key
 * @property string $status
 * @property \Carbon\CarbonImmutable $started_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 */
final class WorkflowInstance extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'workflow_instances';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'case_id',
        'current_node_key',
        'status',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InstanceStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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
