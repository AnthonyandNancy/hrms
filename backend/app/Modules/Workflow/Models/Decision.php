<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Models;

use App\Modules\Workflow\Enums\DecisionAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable decision evidence. Updates and deletes are blocked in the model;
 * production additionally grants only INSERT/SELECT on this table.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $case_id
 * @property string $node_key
 * @property int $actor_user_id
 * @property int $decision_seq
 * @property DecisionAction $action
 * @property string|null $comment
 * @property array<string, mixed> $actor_snapshot_json
 * @property int $template_version
 * @property string|null $evidence_hash
 * @property \Carbon\CarbonImmutable $decided_at
 * @property string|null $request_id
 */
final class Decision extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $table = 'decisions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'case_id',
        'node_key',
        'actor_user_id',
        'decision_seq',
        'action',
        'comment',
        'actor_snapshot_json',
        'template_version',
        'evidence_hash',
        'decided_at',
        'request_id',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \DomainException('Decisions are append-only.');
        });

        self::deleting(function (): never {
            throw new \DomainException('Decisions are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_user_id' => 'integer',
            'decision_seq' => 'integer',
            'action' => DecisionAction::class,
            'actor_snapshot_json' => 'array',
            'template_version' => 'integer',
            'decided_at' => 'immutable_datetime',
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
