<?php

declare(strict_types=1);

namespace App\Modules\Time\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rebuildable projection. Never source of truth; balance always recalculates
 * from leave_ledger_entries.
 */
final class LeaveBalance extends Model
{
    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'tenant_id',
        'employment_id',
        'leave_type_id',
        'as_of',
        'granted',
        'reserved',
        'consumed',
        'released',
        'expired',
        'adjusted',
        'reversed',
        'balance',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_date:Y-m-d',
            'granted' => 'integer',
            'reserved' => 'integer',
            'consumed' => 'integer',
            'released' => 'integer',
            'expired' => 'integer',
            'adjusted' => 'integer',
            'reversed' => 'integer',
            'balance' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
