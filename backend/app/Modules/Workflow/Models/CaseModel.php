<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Models;

use App\Modules\Workflow\Enums\CaseEffectStatus;
use App\Modules\Workflow\Enums\CaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Business intent container. The application status and the independent effect
 * status are intentionally separate columns and separate API fields.
 *
 * @property int $id
 * @property string $case_no
 * @property string $type
 * @property string $subject_type
 * @property int|null $subject_id
 * @property string $title
 * @property array<string, mixed> $payload_json
 * @property CaseStatus $status
 * @property CaseEffectStatus $effect_status
 * @property int $requester_user_id
 * @property int|null $template_id
 * @property int $template_version
 * @property array<string, mixed>|null $template_snapshot_json
 * @property string $business_date
 * @property \Carbon\CarbonImmutable|null $submitted_at
 * @property \Carbon\CarbonImmutable|null $closed_at
 */
final class CaseModel extends Model
{
    protected $table = 'cases';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'case_no',
        'type',
        'subject_type',
        'subject_id',
        'title',
        'payload_json',
        'status',
        'effect_status',
        'requester_user_id',
        'template_id',
        'template_version',
        'template_snapshot_json',
        'business_date',
        'submitted_at',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'status' => CaseStatus::class,
            'effect_status' => CaseEffectStatus::class,
            'subject_id' => 'integer',
            'requester_user_id' => 'integer',
            'template_id' => 'integer',
            'template_version' => 'integer',
            'template_snapshot_json' => 'array',
            'submitted_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasOne<WorkflowInstance, $this>
     */
    public function instance(): HasOne
    {
        return $this->hasOne(WorkflowInstance::class, 'case_id');
    }

    /**
     * @return HasMany<Decision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class, 'case_id');
    }

    /**
     * @return HasMany<Effect, $this>
     */
    public function effects(): HasMany
    {
        return $this->hasMany(Effect::class, 'case_id');
    }

    /**
     * @return HasMany<CaseEvent, $this>
     */
    public function caseEvents(): HasMany
    {
        return $this->hasMany(CaseEvent::class, 'case_id');
    }

    /**
     * @return HasMany<WorkflowTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class, 'case_id');
    }
}
