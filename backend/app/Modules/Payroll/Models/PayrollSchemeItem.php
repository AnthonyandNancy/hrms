<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class PayrollSchemeItem extends Model
{
    use BelongsToTenant;

    protected $table = 'payroll_scheme_items';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'scheme_id',
        'payroll_item_id',
        'item_code',
        'label',
        'category',
        'calculation',
        'amount_cents',
        'rate_scaled',
        'basis',
        'sort_order',
        'taxable',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'rate_scaled' => 'integer',
            'sort_order' => 'integer',
            'taxable' => 'boolean',
        ];
    }
}
