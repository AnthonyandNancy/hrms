<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\CumulativeTaxDetail;
use App\Modules\Payroll\Domain\PayrollCalculationResult;
use App\Modules\Payroll\Domain\PayrollLineSource;
use App\Modules\Payroll\Domain\PayslipLineCategory;
use App\Modules\Payroll\Domain\PayslipLineDraft;
use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayrollCalculationResultInvariantTest extends TestCase
{
    public function test_rejects_a_result_with_a_positive_tax_carry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CumulativeTaxDetail(
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
            carryForwardCents: 1,
        );
    }

    public function test_accepts_a_valid_zero_result(): void
    {
        $result = new PayrollCalculationResult(
            gross: Money::fromMinor(0),
            taxableIncome: Money::fromMinor(0),
            employeeSocialInsurance: Money::fromMinor(0),
            employeeHousingFund: Money::fromMinor(0),
            tax: Money::fromMinor(0),
            otherDeductions: Money::fromMinor(0),
            employeeDeductions: Money::fromMinor(0),
            employerSocialInsurance: Money::fromMinor(0),
            employerHousingFund: Money::fromMinor(0),
            employerCost: Money::fromMinor(0),
            net: Money::fromMinor(0),
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

        $this->assertTrue($result->net->isZero());
        $result->assertBalanced();
    }

    public function test_rejects_deduction_lines_that_do_not_equal_deduction_total(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PayrollCalculationResult(
            gross: Money::fromMinor(100),
            taxableIncome: Money::fromMinor(0),
            employeeSocialInsurance: Money::fromMinor(0),
            employeeHousingFund: Money::fromMinor(0),
            tax: Money::fromMinor(0),
            otherDeductions: Money::fromMinor(20),
            employeeDeductions: Money::fromMinor(20),
            employerSocialInsurance: Money::fromMinor(0),
            employerHousingFund: Money::fromMinor(0),
            employerCost: Money::fromMinor(100),
            net: Money::fromMinor(80),
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
            lines: [
                new PayslipLineDraft(
                    itemCode: 'BASE',
                    category: PayslipLineCategory::EARNING,
                    amount: Money::fromMinor(100),
                    source: new PayrollLineSource('employment_terms', '1'),
                    sortOrder: 10,
                ),
                new PayslipLineDraft(
                    itemCode: 'OTHER',
                    category: PayslipLineCategory::DEDUCTION,
                    amount: Money::fromMinor(10),
                    source: new PayrollLineSource('deduction_instruction', '1'),
                    sortOrder: 20,
                ),
            ],
        );
    }
}
