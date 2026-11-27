<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An append-history proposal to change an approved or pending travel request.
 * The original BusinessTripRecord is never mutated until the linked Workflow
 * case is approved and TravelService applies the stored proposed_json.
 *
 * @property int $id
 * @property int $travel_id
 * @property int $employment_id
 * @property int|null $case_id
 * @property array<string, mixed> $original_json
 * @property array<string, mixed> $proposed_json
 * @property string $reason
 * @property string $status
 * @property \Carbon\CarbonImmutable $recorded_at
 * @property \Carbon\CarbonImmutable|null $applied_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class TravelRequestChange extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPLIED = 'APPLIED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'travel_request_changes';

    /** @var list<string> */
    protected $fillable = [
        'travel_id',
        'employment_id',
        'case_id',
        'original_json',
        'proposed_json',
        'reason',
        'status',
        'recorded_at',
        'applied_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'travel_id' => 'integer',
            'employment_id' => 'integer',
            'case_id' => 'integer',
            'original_json' => 'array',
            'proposed_json' => 'array',
            'recorded_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
