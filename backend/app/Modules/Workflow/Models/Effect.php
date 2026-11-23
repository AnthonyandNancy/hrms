<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Models;

use App\Modules\Workflow\Enums\EffectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $case_id
 * @property int|null $decision_id
 * @property string $effect_type
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property int $effect_seq
 * @property EffectStatus $status
 * @property \Carbon\CarbonImmutable|null $scheduled_for
 * @property \Carbon\CarbonImmutable|null $applied_at
 * @property \Carbon\CarbonImmutable|null $failed_at
 * @property string|null $failure_reason
 * @property int $attempts
 * @property int|null $reversal_of_id
 * @property array<string, mixed>|null $payload_json
 * @property bool $requires_escalation
 */
final class Effect extends Model
{
    protected $table = 'effects';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'case_id',
        'decision_id',
        'effect_type',
        'aggregate_type',
        'aggregate_id',
        'effect_seq',
        'status',
        'scheduled_for',
        'applied_at',
        'failed_at',
        'failure_reason',
        'attempts',
        'reversal_of_id',
        'payload_json',
        'requires_escalation',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decision_id' => 'integer',
            'effect_seq' => 'integer',
            'status' => EffectStatus::class,
            // A DATE column, not DATETIME: serialized as Y-m-d so SQL
            // comparisons against today's date are unambiguous on SQLite/MySQL.
            'scheduled_for' => 'date',
            'applied_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'reversal_of_id' => 'integer',
            'payload_json' => 'array',
            'requires_escalation' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CaseModel, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CaseModel::class, 'case_id');
    }

    /**
     * @return BelongsTo<Decision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class, 'decision_id');
    }
}
