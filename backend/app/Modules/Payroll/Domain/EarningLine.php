<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Money;
use InvalidArgumentException;

/**
 * A pre-calculated earning input such as an allowance or one-time bonus.
 * Informational lines are rejected by the constructor.
 */
final readonly class EarningLine
{
    public string $sourceType;

    public string $sourceId;

    public function __construct(
        public string $itemCode,
        public string $label,
        public Money $amount,
        public PayrollLineSource $source,
        public int $sortOrder,
        public bool $taxable = true,
        public int $quantityScaled = 0,
        public string $unit = 'MONTH',
        public int $rateScaled = 0,
    ) {
        if (trim($itemCode) === '' || trim($label) === '') {
            throw new InvalidArgumentException('Earning line code and label are mandatory.');
        }

        if ($amount->isNegative()) {
            throw new InvalidArgumentException('Earning lines cannot hold negative amounts; use a deduction or manual adjustment.');
        }

        if ($sortOrder < 0 || $quantityScaled < 0 || $rateScaled < 0) {
            throw new InvalidArgumentException('Earning line sort order, quantity, and rate cannot be negative.');
        }

        $this->sourceType = $source->type;
        $this->sourceId = $source->id;
    }

    public function toPayslipLine(?int $sortOrder = null): PayslipLineDraft
    {
        return new PayslipLineDraft(
            itemCode: $this->itemCode,
            category: PayslipLineCategory::EARNING,
            amount: $this->amount,
            source: $this->source,
            sortOrder: $sortOrder ?? $this->sortOrder,
            quantityScaled: $this->quantityScaled,
            unit: $this->unit,
            rateScaled: $this->rateScaled,
            taxable: $this->taxable,
        );
    }
}
