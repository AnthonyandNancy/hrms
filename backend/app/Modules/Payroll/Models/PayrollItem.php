<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stable payroll-item identity only. Code is the business key and status is the
 * item's current lifecycle state; every mutable descriptive or calculation
 * attribute lives on an append-only PayrollItemVersion row.
 *
 * @property int $id
 * @property string $code
 * @property string $status
 * @property \Illuminate\Database\Eloquent\Collection<int, PayrollItemVersion> $versions
 */
final class PayrollItem extends Model
{
    protected $table = 'payroll_items';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'status',
    ];

    /**
     * @return HasMany<PayrollItemVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PayrollItemVersion::class, 'payroll_item_id')
            ->orderByDesc('version')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'string',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (PayrollItem $item): void {
            // Code is the stable business key used by payroll_scheme_items and
            // historical snapshots. Retiring an item is the only mutation on
            // the identity row; everything else belongs to a new version.
            $changed = array_values(array_diff(array_keys($item->getDirty()), ['status', 'updated_at']));

            if ($changed !== []) {
                throw new \DomainException('Payroll item identity fields are immutable: '.implode(', ', $changed));
            }
        });

        self::deleting(function (): never {
            throw new \DomainException('Payroll items are never deleted; retire the item and keep its history.');
        });
    }
}
