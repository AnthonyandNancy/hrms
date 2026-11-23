<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class TaxBracket extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'tax_brackets';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'parameter_set_id',
        'lower_bound_cents',
        'upper_bound_cents',
        'rate_bps',
        'quick_deduction_cents',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameter_set_id' => 'integer',
            'lower_bound_cents' => 'integer',
            'upper_bound_cents' => 'integer',
            'rate_bps' => 'integer',
            'quick_deduction_cents' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \DomainException('Tax brackets are append-only versions.');
        });

        self::deleting(function (): never {
            throw new \DomainException('Tax brackets are append-only versions.');
        });
    }
}
