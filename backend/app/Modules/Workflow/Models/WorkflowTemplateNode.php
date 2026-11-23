<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $template_id
 * @property string $key
 * @property string $name
 * @property string $type
 * @property int $sequence
 * @property array<string, mixed>|null $assignee_rule_json
 * @property int $required_count
 * @property int|null $sla_hours
 */
final class WorkflowTemplateNode extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'workflow_template_nodes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'template_id',
        'key',
        'name',
        'type',
        'sequence',
        'assignee_rule_json',
        'required_count',
        'sla_hours',
        'condition_json',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'assignee_rule_json' => 'array',
            'required_count' => 'integer',
            'sla_hours' => 'integer',
            'condition_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WorkflowTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'template_id');
    }
}
