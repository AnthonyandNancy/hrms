<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\PayrollItemType;
use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $legal_entity_id
 * @property string $code
 * @property string $name
 * @property PayrollItemType $type
 * @property bool $taxable
 * @property bool $insurable
 * @property string $calculation
 * @property array<string, mixed>|null $formula_json
 * @property int $sort_order
 * @property string $status
 */
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
