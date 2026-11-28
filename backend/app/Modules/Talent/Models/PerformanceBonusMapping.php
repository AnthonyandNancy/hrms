<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Approved mapping from a published performance result to one payroll bonus
 * item. DRAFT/REVOKED mappings never enter Payroll snapshots.
 *
 * @property int $id
 * @property int $performance_snapshot_id
 * @property int $cycle_id
 * @property int $employment_id
 * @property string $payroll_item_code
 * @property string $period
 * @property int $amount_cents
 * @property string $mapping_version
 * @property string $status
 * @property int|null $approved_by
 * @property \Carbon\CarbonImmutable|null $approved_at
 * @property string $source_revision
 * @property \Carbon\CarbonImmutable $created_at
 */
final class PerformanceBonusMapping extends Model
{
    protected $table = 'performance_bonus_mappings';

    /** @var list<string> */
    protected $fillable = [
        'performance_snapshot_id',
        'cycle_id',
        'employment_id',
        'payroll_item_code',
        'period',
        'amount_cents',
        'mapping_version',
        'status',
        'approved_by',
        'approved_at',
        'source_revision',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performance_snapshot_id' => 'integer',
            'cycle_id' => 'integer',
            'employment_id' => 'integer',
            'amount_cents' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
