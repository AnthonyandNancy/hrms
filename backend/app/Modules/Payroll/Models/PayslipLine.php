<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class PayslipLine extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'payslip_lines';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'payslip_id',
        'item_code',
        'category',
        'quantity_scaled',
        'unit',
        'rate_scaled',
        'amount_cents',
        'taxable',
        'source_type',
        'source_id',
        'sort_order',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_scaled' => 'integer',
            'rate_scaled' => 'integer',
            'amount_cents' => 'integer',
            'taxable' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \DomainException('Payslip lines are immutable once written.');
        });

        self::deleting(function (PayslipLine $line): void {
            $payslip = Payslip::query()->whereKey($line->payslip_id)->first();

            if ($payslip === null || $payslip->published_at !== null) {
                throw new \DomainException('Published payslip lines are immutable.');
            }
        });
    }
}
