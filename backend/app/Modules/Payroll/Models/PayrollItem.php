<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\PayrollItemType;
use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class PayrollItem extends Model
{
    use BelongsToTenant;

    protected $table = 'payroll_items';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'code',
        'name',
        'type',
        'taxable',
        'insurable',
        'calculation',
        'formula_json',
        'sort_order',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PayrollItemType::class,
            'taxable' => 'boolean',
            'insurable' => 'boolean',
            'formula_json' => 'array',
            'sort_order' => 'integer',
        ];
    }
}
