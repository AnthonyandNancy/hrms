<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Money;
use InvalidArgumentException;

/**
 * Balanced gross-to-net result. assertBalanced() is intentionally called in
 * the constructor so an incorrect total cannot leave the domain layer.
 */
final readonly class PayrollCalculationResult
{
    /**
     * @param  list<PayslipLineDraft>  $lines
     * @param  list<string>  $warnings
     */
    public function __construct(
        public Money $gross,
        public Money $taxableIncome,
        public Money $employeeSocialInsurance,
        public Money $employeeHousingFund,
        public Money $tax,
        public Money $otherDeductions,
        public Money $employeeDeductions,
        public Money $employerSocialInsurance,
        public Money $employerHousingFund,
        public Money $employerCost,
        public Money $net,
        public CumulativeTaxDetail $cumulativeTax,
        public array $lines,
        public array $warnings = [],
        public ?Money $otherEmployerCosts = null,
    ) {
        $this->assertBalanced();
    }

    public function assertBalanced(): void
    {
        $currency = $this->gross->currency();
        foreach ([
            $this->taxableIncome,
            $this->employeeSocialInsurance,
            $this->employeeHousingFund,
            $this->tax,
            $this->otherDeductions,
            $this->employeeDeductions,
            $this->employerSocialInsurance,
            $this->employerHousingFund,
            $this->employerCost,
            $this->net,
            ...($this->otherEmployerCosts === null ? [] : [$this->otherEmployerCosts]),
        ] as $money) {
            $this->assertCurrency($money, $currency);
        }

        $earningTotal = 0;
        $deductionTotal = 0;
        $employerTotal = 0;
        foreach ($this->lines as $line) {
            $this->assertCurrency($line->amount, $currency);

            if ($line->amount->isNegative()) {
                throw new InvalidArgumentException('Payslip line amounts must be non-negative.');
            }

            if ($line->category->isEarning()) {
                $earningTotal = $this->checkedAdd($earningTotal, $line->amount->minor());
            } elseif ($line->category->isDeduction()) {
                $deductionTotal = $this->checkedAdd($deductionTotal, $line->amount->minor());
            } elseif ($line->category->isEmployerCost()) {
                $employerTotal = $this->checkedAdd($employerTotal, $line->amount->minor());
            }
        }

        if ($earningTotal !== $this->gross->minor()) {
            throw new InvalidArgumentException('Gross must equal the sum of earning lines.');
        }

        if ($deductionTotal !== $this->employeeDeductions->minor()) {
            throw new InvalidArgumentException('Employee deductions must equal the sum of deduction lines.');
        }

        if ($this->gross->minor() - $deductionTotal !== $this->net->minor()) {
            throw new InvalidArgumentException('Net must equal gross minus employee deductions.');
        }

        if ($this->employeeSocialInsurance->minor() + $this->employeeHousingFund->minor()
            + $this->tax->minor() + $this->otherDeductions->minor() !== $this->employeeDeductions->minor()) {
            throw new InvalidArgumentException('Employee deduction components must sum to total deductions.');
        }

        $otherEmployerCost = $this->otherEmployerCosts?->minor() ?? 0;

        if ($this->gross->minor() + $this->employerSocialInsurance->minor()
            + $this->employerHousingFund->minor() + $otherEmployerCost !== $this->employerCost->minor()) {
            throw new InvalidArgumentException('Employer cost must equal gross plus employer contributions.');
        }

        if ($employerTotal !== $this->employerSocialInsurance->minor() + $this->employerHousingFund->minor() + $otherEmployerCost) {
            throw new InvalidArgumentException('Employer cost lines must equal employer contributions.');
        }

        if ($this->net->minor() === 0 && str_starts_with($this->net->toDecimalString(), '-')) {
            throw new InvalidArgumentException('Negative zero is forbidden.');
        }
    }

    private function checkedAdd(int $left, int $right): int
    {
        $result = bcadd((string) $left, (string) $right, 0);
        if (bccomp($result, (string) PHP_INT_MAX, 0) > 0
            || bccomp($result, (string) PHP_INT_MIN, 0) < 0) {
            throw new InvalidArgumentException('Payslip line totals exceed the native integer range.');
        }

        return (int) $result;
    }

    private function assertCurrency(Money $money, string $currency): void
    {
        if ($money->currency() !== $currency) {
            throw new InvalidArgumentException('All result amounts must use one currency.');
        }
    }
}
