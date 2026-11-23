<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Money;
use InvalidArgumentException;

/**
 * A payslip line before persistence. Monetary values are integer cents only.
 * sourceType/sourceId mirror the mandatory PayrollLineSource for consumers
 * that persist the denormalized columns.
 */
final readonly class PayslipLineDraft
{
    public string $sourceType;

    public string $sourceId;

    public function __construct(
        public string $itemCode,
        public PayslipLineCategory $category,
        public Money $amount,
        public PayrollLineSource $source,
        public int $sortOrder,
        public int $quantityScaled = 0,
        public string $unit = 'MINUTE',
        public int $rateScaled = 0,
        public bool $taxable = true,
    ) {
        if (trim($this->itemCode) === '') {
            throw new InvalidArgumentException('Payslip line item code is mandatory.');
        }

        if ($amount->isNegative()) {
            throw new InvalidArgumentException('Payslip line amounts must be non-negative.');
        }

        if ($sortOrder < 0) {
            throw new InvalidArgumentException('Payslip line sort order cannot be negative.');
        }

        if ($quantityScaled < 0 || $rateScaled < 0) {
            throw new InvalidArgumentException('Payslip line quantity and rate cannot be negative.');
        }

        $this->sourceType = $source->type;
        $this->sourceId = $source->id;
    }
}
