<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\CumulativeTaxDetail;
use App\Modules\Payroll\Domain\PayrollCalculationResult;
use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayrollCalculationResultTest extends TestCase
{
    public function test_rejects_a_component_in_a_different_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PayrollCalculationResult(
            gross: Money::fromMinor(0, 'CNY'),
            taxableIncome: Money::fromMinor(0, 'USD'),
            employeeSocialInsurance: Money::fromMinor(0, 'CNY'),
            employeeHousingFund: Money::fromMinor(0, 'CNY'),
            tax: Money::fromMinor(0, 'CNY'),
            otherDeductions: Money::fromMinor(0, 'CNY'),
            employeeDeductions: Money::fromMinor(0, 'CNY'),
            employerSocialInsurance: Money::fromMinor(0, 'CNY'),
            employerHousingFund: Money::fromMinor(0, 'CNY'),
            employerCost: Money::fromMinor(0, 'CNY'),
            net: Money::fromMinor(0, 'CNY'),
            cumulativeTax: new CumulativeTaxDetail(
                taxTableVersion: 'DEMO',
                taxYear: 2026,
                ytdTaxableCents: 0,
                currentTaxableCents: 0,
                cumulativeTaxableCents: 0,
                thresholdCents: 0,
                specialDeductionsCents: 0,
                ytdTaxWithheldCents: 0,
                cumulativeTaxCents: 0,
                currentTaxCents: 0,
                carryForwardCents: 0,
            ),
            lines: [],
        );
    }
}
