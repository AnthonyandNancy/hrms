<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class PayrollAdjustment extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'payroll_adjustments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
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
