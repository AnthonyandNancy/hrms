<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Domain projection of one lifecycle Case. It is not an approval status copy:
 * `status` tracks local draft/effect projection only, while Workflow owns the
 * case application and effect states.
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
        'employment_id',
        'result_employment_id',
        'effective_at',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'case_id' => 'integer',
            'subject_id' => 'integer',
            'intent_json' => 'array',
            'employment_id' => 'integer',
            'result_employment_id' => 'integer',
            'effective_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
