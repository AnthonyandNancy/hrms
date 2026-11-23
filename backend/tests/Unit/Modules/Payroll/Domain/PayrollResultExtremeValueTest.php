<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\CumulativeTaxDetail;
use App\Modules\Payroll\Domain\PayrollCalculationResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayrollResultExtremeValueTest extends TestCase
{
    public function test_result_balance_check_never_overflows_on_extreme_component_sums(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Both component sums overflow native int; the result validator must
        // reject the inconsistent result rather than compare a wrapped value.
        new PayrollCalculationResult(
            gross: \App\Shared\Money\Money::fromMinor(0),
            taxableIncome: \App\Shared\Money\Money::fromMinor(0),
            employeeSocialInsurance: \App\Shared\Money\Money::fromMinor(PHP_INT_MAX),
            employeeHousingFund: \App\Shared\Money\Money::fromMinor(PHP_INT_MAX),
            tax: \App\Shared\Money\Money::fromMinor(PHP_INT_MAX),
            otherDeductions: \App\Shared\Money\Money::fromMinor(PHP_INT_MAX),
            employeeDeductions: \App\Shared\Money\Money::fromMinor(0),
            employerSocialInsurance: \App\Shared\Money\Money::fromMinor(PHP_INT_MAX),
            employerHousingFund: \App\Shared\Money\Money::fromMinor(PHP_INT_MAX),
            employerCost: \App\Shared\Money\Money::fromMinor(0),
            net: \App\Shared\Money\Money::fromMinor(0),
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
