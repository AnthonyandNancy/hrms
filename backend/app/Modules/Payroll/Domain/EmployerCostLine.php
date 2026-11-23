<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Money;
use InvalidArgumentException;

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
    ) {
        if (trim($itemCode) === '' || trim($label) === '') {
            throw new InvalidArgumentException('Employer cost line code and label are mandatory.');
        }

        if ($amount->isNegative()) {
            throw new InvalidArgumentException('Employer cost lines cannot hold negative amounts.');
        }

        if ($sortOrder < 0) {
            throw new InvalidArgumentException('Employer cost line sort order cannot be negative.');
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
            taxable: false,
        );
    }
}
