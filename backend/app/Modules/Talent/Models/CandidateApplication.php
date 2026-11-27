<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A candidate's application to one requisition. This is the recruitment fact
 * that links a candidate, a requisition and an optional channel opening.
 * Interviews, evaluations and offers hang off this same business association.
 */
/**
 * @property \Carbon\CarbonImmutable|null $recorded_at
 */
final class CandidateApplication extends Model
{
    public const STAGES = [
        'NEW',
        'SCREENING',
        'INTERVIEW',
        'OFFER',
        'OFFER_ACCEPTED',
        'CONVERTED',
        'REJECTED',
    ];

    public const STATUSES = ['ACTIVE', 'REJECTED', 'CONVERTED'];

    protected $table = 'candidate_applications';

    /** @var list<string> */
    protected $fillable = [
        'candidate_id',
        'requisition_id',
        'opening_id',
        'stage',
        'status',
        'source',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'candidate_id' => 'integer',
            'requisition_id' => 'integer',
            'opening_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
