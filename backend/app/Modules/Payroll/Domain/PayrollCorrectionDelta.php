<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Money;
use InvalidArgumentException;

/**
 * Signed monetary difference between a correction run and the original run.
 * Original + delta must equal the revised calculation exactly. Delta fields
 * may be negative for a recovery (for example a clawback).
 */
final readonly class PayrollCorrectionDelta
{
    public function __construct(
        public Money $gross,
        public Money $taxableIncome,
        public Money $employeeSocialInsurance,
        public Money $employeeHousingFund,
        public Money $tax,
        public Money $employeeDeductions,
        public Money $employerSocialInsurance,
        public Money $employerHousingFund,
        public Money $employerCost,
        public Money $net,
    ) {
        $currency = $this->gross->currency();
        foreach ([
            $this->taxableIncome,
            $this->employeeSocialInsurance,
            $this->employeeHousingFund,
            $this->tax,
            $this->employeeDeductions,
            $this->employerSocialInsurance,
            $this->employerHousingFund,
            $this->employerCost,
            $this->net,
        ] as $money) {
            if ($money->currency() !== $currency) {
                throw new InvalidArgumentException('Correction delta amounts must use one currency.');
            }
        }

        if ($this->gross->minor() - $this->employeeDeductions->minor() !== $this->net->minor()) {
            throw new InvalidArgumentException('Correction net delta must equal gross delta minus deduction delta.');
        }

        if ($this->gross->minor() + $this->employerSocialInsurance->minor()
            + $this->employerHousingFund->minor() !== $this->employerCost->minor()) {
            throw new InvalidArgumentException('Correction employer-cost delta must follow the employer-cost formula.');
        }
    }

    public static function between(PayrollCalculationResult $original, PayrollCalculationResult $revised): self
    {
        if ($original->gross->currency() !== $revised->gross->currency()) {
            throw new InvalidArgumentException('Original and revised calculations must use one currency.');
        }

        return new self(
            gross: $revised->gross->subtract($original->gross),
            taxableIncome: $revised->taxableIncome->subtract($original->taxableIncome),
            employeeSocialInsurance: $revised->employeeSocialInsurance->subtract($original->employeeSocialInsurance),
            employeeHousingFund: $revised->employeeHousingFund->subtract($original->employeeHousingFund),
            tax: $revised->tax->subtract($original->tax),
            employeeDeductions: $revised->employeeDeductions->subtract($original->employeeDeductions),
            employerSocialInsurance: $revised->employerSocialInsurance->subtract($original->employerSocialInsurance),
            employerHousingFund: $revised->employerHousingFund->subtract($original->employerHousingFund),
            employerCost: $revised->employerCost->subtract($original->employerCost),
            net: $revised->net->subtract($original->net),
        );
    }

    public function isZero(): bool
    {
        return $this->gross->isZero()
            && $this->employeeDeductions->isZero()
            && $this->net->isZero()
            && $this->employerCost->isZero();
    }
}
