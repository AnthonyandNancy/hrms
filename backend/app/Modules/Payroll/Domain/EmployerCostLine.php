<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Money;
use InvalidArgumentException;

/**
 * Employer-only cost supplied by an authorized payroll input port (for
 * example an employee benefit plan). It never changes gross or net.
 */
final readonly class EmployerCostLine
{
    public string $sourceType;

    public string $sourceId;

    public function __construct(
        public string $itemCode,
        public string $label,
        public Money $amount,
        public PayrollLineSource $source,
        public int $sortOrder,
        public int $quantityScaled = 0,
        public string $unit = 'MONTH',
        public int $rateScaled = 0,
    ) {
        if (trim($itemCode) === '' || trim($label) === '') {
            throw new InvalidArgumentException('Employer cost line code and label are mandatory.');
        }

        if ($amount->isNegative()) {
            throw new InvalidArgumentException('Employer cost lines must hold a non-negative amount.');
        }

        if ($sortOrder < 0 || $quantityScaled < 0 || $rateScaled < 0) {
            throw new InvalidArgumentException('Employer cost line sort order, quantity, and rate cannot be negative.');
        }

        $this->sourceType = $source->type;
        $this->sourceId = $source->id;
    }

    public function toPayslipLine(?int $sortOrder = null): PayslipLineDraft
    {
        return new PayslipLineDraft(
            itemCode: $this->itemCode,
            category: PayslipLineCategory::EMPLOYER_COST,
            amount: $this->amount,
            source: $this->source,
            sortOrder: $sortOrder ?? $this->sortOrder,
            quantityScaled: $this->quantityScaled,
            unit: $this->unit,
            rateScaled: $this->rateScaled,
            taxable: false,
        );
    }
}
