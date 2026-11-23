<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Money;
use InvalidArgumentException;

/**
 * A signed, approved correction to net pay. A positive amount is a supplement
 * (earning); a negative amount is a recovery (deduction). Zero is invalid.
 */
final readonly class ManualAdjustment
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
    ) {
        if (trim($itemCode) === '' || trim($label) === '') {
            throw new InvalidArgumentException('Manual adjustment code and label are mandatory.');
        }

        if ($amount->isZero()) {
            throw new InvalidArgumentException('Manual adjustment amount cannot be zero.');
        }

        if ($sortOrder < 0) {
            throw new InvalidArgumentException('Manual adjustment sort order cannot be negative.');
        }

        $this->sourceType = $source->type;
        $this->sourceId = $source->id;
    }

    public function isEarning(): bool
    {
        return ! $this->amount->isNegative();
    }

    public function absoluteAmount(): Money
    {
        return Money::fromMinor(abs($this->amount->minor()), $this->amount->currency());
    }

    public function toPayslipLine(?int $sortOrder = null): PayslipLineDraft
    {
        return new PayslipLineDraft(
            itemCode: $this->itemCode,
            category: $this->isEarning() ? PayslipLineCategory::EARNING : PayslipLineCategory::DEDUCTION,
            amount: $this->absoluteAmount(),
            source: $this->source,
            sortOrder: $sortOrder ?? $this->sortOrder,
            taxable: $this->taxable,
        );
    }
}
