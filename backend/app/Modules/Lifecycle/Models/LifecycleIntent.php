<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Domain projection of one lifecycle Case. It is not an approval status copy:
 * `status` tracks local draft/effect projection only, while Workflow owns the
 * case application and effect states.
 *
 * `before_json`, `after_json` and `effect_result_json` are frozen at the
 * approval/effect boundary. Detail reads must use these snapshots rather than
 * reinterpreting live People state, otherwise a later promotion or transfer
 * would silently rewrite the history the approver actually saw.
 */
/**
 * @property array<string, mixed>|null $intent_json
 * @property \Carbon\CarbonImmutable|null $effective_at
 * @property \Carbon\CarbonImmutable|null $recorded_at
 * @property \Carbon\CarbonImmutable|null $detail_frozen_at
 * @property string|null $planned_effective_on
 */
final class LifecycleIntent extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_EFFECTIVE = 'EFFECTIVE';

    public const STATUS_FAILED = 'FAILED';

    protected $table = 'lifecycle_intents';

    /** @var list<string> */
    protected $fillable = [
        'case_id',
        'case_type',
        'status',
        'subject_type',
        'subject_id',
        'intent_json',
        'offer_id',
        'employment_id',
        'result_employment_id',
        'planned_effective_on',
        'deduplication_key',
        'effective_at',
        'recorded_at',
        'before_json',
        'after_json',
        'effect_result_json',
        'detail_frozen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'case_id' => 'integer',
            'offer_id' => 'integer',
            'subject_id' => 'integer',
            'intent_json' => 'array',
            'employment_id' => 'integer',
            'result_employment_id' => 'integer',
            'effective_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'before_json' => 'array',
            'after_json' => 'array',
            'effect_result_json' => 'array',
            'detail_frozen_at' => 'immutable_datetime',
        ];
    }
}
