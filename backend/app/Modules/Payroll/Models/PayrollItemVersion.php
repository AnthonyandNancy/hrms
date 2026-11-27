<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\PayrollItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only payroll-item version. A new effective period appends a row; old
 * rows stay readable and are never rewritten by the catalog UI. Retirement
 * stamps retired_at/retire_reason but never deletes history.
 *
 * @property int $id
 * @property int $payroll_item_id
 * @property int $version
 * @property string $name
 * @property PayrollItemType $type
 * @property bool $taxable
 * @property bool $insurable
 * @property string $calculation
 * @property array<string, mixed>|null $formula_json
 * @property int $sort_order
 * @property \Carbon\CarbonImmutable $effective_from
 * @property \Carbon\CarbonImmutable $effective_to
 * @property \Carbon\CarbonImmutable|null $retired_at
 * @property string|null $retire_reason
 * @property string $status
 * @property \Carbon\CarbonImmutable $recorded_at
 * @property PayrollItem $item
 */
final class PayrollItemVersion extends Model
{
    protected $table = 'payroll_item_versions';

    /** @var list<string> */
    protected $fillable = [
        'payroll_item_id',
        'version',
        'name',
        'type',
        'taxable',
        'insurable',
        'calculation',
        'formula_json',
        'sort_order',
        'effective_from',
        'effective_to',
        'retired_at',
        'retire_reason',
        'status',
        'recorded_at',
    ];

    /**
     * @return BelongsTo<PayrollItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payroll_item_id' => 'integer',
            'version' => 'integer',
            'type' => PayrollItemType::class,
            'taxable' => 'boolean',
            'insurable' => 'boolean',
            'formula_json' => 'array',
            'sort_order' => 'integer',
            'effective_from' => 'immutable_date:Y-m-d',
            'effective_to' => 'immutable_date:Y-m-d',
            'retired_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (PayrollItemVersion $version): void {
            // Append-only business facts. Appending may only shorten the
            // previous effective_to as structural supersession; retirement may
            // stamp lifecycle fields. A name/range/formula rewrite must create
            // a new version row instead of mutating history.
            $mutable = ['effective_to', 'status', 'retired_at', 'retire_reason', 'updated_at'];
            $changed = array_values(array_diff(array_keys($version->getDirty()), $mutable));

            if ($changed !== []) {
                throw new \DomainException('Payroll item versions are append-only: '.implode(', ', $changed));
            }
        });

        self::deleting(function (): never {
            throw new \DomainException('Payroll item versions are never deleted.');
        });
    }
}
