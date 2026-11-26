<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $scheme_id
 * @property int|null $payroll_item_id
 * @property string $item_code
 * @property string $label
 * @property string $category
 * @property string $calculation
 * @property int $amount_cents
 * @property int|null $rate_scaled
 * @property string $basis
 * @property int $sort_order
 * @property bool $taxable
 */
final class PayrollSchemeItem extends Model
{
    protected $table = 'payroll_scheme_items';

    /** @var list<string> */
    protected $fillable = [
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
