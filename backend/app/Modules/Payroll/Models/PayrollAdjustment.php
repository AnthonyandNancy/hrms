<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $run_id
 * @property int $employment_id
 * @property string $item_code
 * @property int $amount_cents
 * @property string $reason
 * @property int|null $case_id
 * @property int $approved_by
 * @property string $effective_period
 * @property \Carbon\CarbonImmutable $created_at
 */
final class PayrollAdjustment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'payroll_adjustments';

    /** @var list<string> */
    protected $fillable = [
        'run_id',
        'employment_id',
        'item_code',
        'amount_cents',
        'reason',
        'case_id',
        'approved_by',
        'effective_period',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'case_id' => 'integer',
            'approved_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
