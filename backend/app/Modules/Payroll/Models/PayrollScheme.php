<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PayrollScheme extends Model
{
    use BelongsToTenant;

    protected $table = 'payroll_schemes';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'legal_entity_id',
        'code',
        'name',
        'frequency',
        'currency',
        'version',
        'status',
        'valid_from',
        'valid_to',
        'proration_divisor',
        'rounding_mode',
        'overtime_multiplier',
        'allow_negative_net',
        'base_salary_cents',
    ];

    /**
     * @return HasMany<PayrollSchemeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PayrollSchemeItem::class, 'scheme_id')->orderBy('sort_order');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'allow_negative_net' => 'boolean',
            'base_salary_cents' => 'integer',
            'valid_from' => 'immutable_date:Y-m-d',
            'valid_to' => 'immutable_date:Y-m-d',
        ];
    }
}
