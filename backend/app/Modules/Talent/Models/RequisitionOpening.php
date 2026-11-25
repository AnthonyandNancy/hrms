<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A published hiring opening for an approved requisition. One requisition may
 * have several channel-specific postings; history is retained by status.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $requisition_id
 * @property string $channel
 * @property string $status
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property \Carbon\CarbonImmutable|null $closed_at
 * @property \Carbon\CarbonImmutable $recorded_at
 */
final class RequisitionOpening extends Model
{
    protected $table = 'requisition_openings';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'requisition_id',
        'channel',
        'status',
        'published_at',
        'closed_at',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'requisition_id' => 'integer',
            'published_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
