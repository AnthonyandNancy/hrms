<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\DeductionLine;
use App\Modules\Payroll\Domain\PayrollCalculator;
use App\Modules\Payroll\Domain\PayrollLineSource;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Modules\Payroll\Domain\YtdTaxSnapshot;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class CumulativeWithholdingTest extends TestCase
{
    use PayrollTestFixtures;

    public function test_january_progression_starts_from_empty_ytd(): void
    {
        $result = $this->calculate(1_000_000, 2026, 1, 0);

        $this->assertSame(500_000, $result->taxableIncome->minor());
        $this->assertSame(29_000, $result->cumulativeTax->cumulativeTaxCents);
        $this->assertSame(29_000, $result->tax->minor());
        $this->assertSame(0, $result->cumulativeTax->carryForwardCents);
    }

    public function test_february_uses_cumulative_tax_minus_january_withholding(): void
    {
        $result = $this->calculate(
            grossCents: 1_000_000,
            year: 2026,
            month: 2,
            specialDeductionsCents: 200_000,
            ytd: new YtdTaxSnapshot(2026, 500_000, 29_000),
        );

        // Cumulative taxable = 500,000 + 300,000 = 800,000; table tax = 59,000;
        // minus 29,000 withheld in January leaves 30,000 for February.
        $this->assertSame(800_000, $result->cumulativeTax->cumulativeTaxableCents);
        $this->assertSame(59_000, $result->cumulativeTax->cumulativeTaxCents);
        $this->assertSame(30_000, $result->tax->minor());
        $this->assertSame(200_000, $result->cumulativeTax->specialDeductionsCents);
        $this->assertSame(500_000, $result->cumulativeTax->thresholdCents);
    }

    public function test_march_changing_deductions_recompute_the_full_year(): void
    {
        $result = $this->calculate(
            grossCents: 1_000_000,
            year: 2026,
            month: 3,
            specialDeductionsCents: 100_000,
            ytd: new YtdTaxSnapshot(2026, 800_000, 59_000),
        );

        // Cumulative taxable = 800,000 + 400,000 = 1,200,000; table tax = 99,000.
        $this->assertSame(99_000, $result->cumulativeTax->cumulativeTaxCents);
        $this->assertSame(40_000, $result->tax->minor());
    }

    public function test_negative_difference_withholds_zero_and_carries_forward(): void
    {
        $result = $this->calculate(
            grossCents: 1_000_000,
            year: 2026,
            month: 2,
            specialDeductionsCents: 200_000,
            ytd: new YtdTaxSnapshot(2026, 0, 15_000),
        );

        // Current taxable = 1,000,000 - 200,000 - 500,000 = 300,000;
        // table tax is 9,000; 9,000 - 15,000 = -6,000.
        $this->assertSame(0, $result->tax->minor());
        $this->assertSame(-6_000, $result->cumulativeTax->carryForwardCents);
        $this->assertSame(9_000, $result->cumulativeTax->cumulativeTaxCents);
    }

    public function test_zero_current_taxable_income_never_refunds_prior_withholding(): void
    {
        $result = $this->calculate(
            grossCents: 300_000,
            year: 2026,
            month: 2,
            specialDeductionsCents: 0,
            ytd: new YtdTaxSnapshot(2026, 400_000, 29_000),
        );

        $this->assertSame(0, $result->taxableIncome->minor());
        $this->assertSame(0, $result->tax->minor());
        $this->assertSame(19_000, $result->cumulativeTax->cumulativeTaxCents);
        $this->assertSame(-10_000, $result->cumulativeTax->carryForwardCents);
    }

    public function test_year_rollover_starts_a_fresh_cumulative_tax_year(): void
    {
        $result = $this->calculate(
            grossCents: 1_000_000,
            year: 2027,
            month: 1,
            specialDeductionsCents: 0,
            ytd: new YtdTaxSnapshot(2027),
        );

        $this->assertSame(2027, $result->cumulativeTax->taxYear);
        $this->assertSame(500_000, $result->cumulativeTax->cumulativeTaxableCents);
        $this->assertSame(29_000, $result->tax->minor());
    }

    public function test_prior_year_ytd_snapshot_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeInput(
            baseSalaryCents: 1_000_000,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod(2027, 1),
            context: $this->context(),
            ytd: new YtdTaxSnapshot(2026, 900_000, 50_000),
        );
    }

    public function test_correction_delta_is_revised_result_minus_original_result(): void
    {
        $original = $this->calculate(1_000_000, 2026, 3, 0);
        $revised = $this->calculate(1_100_000, 2026, 3, 0);

        // Full recalculation from the same opening YTD gives the revised
        // liability; a correction pays only the difference.
        $grossDelta = $revised->gross->minor() - $original->gross->minor();
        $taxDelta = $revised->tax->minor() - $original->tax->minor();
        $netDelta = $revised->net->minor() - $original->net->minor();

        $this->assertSame(100_000, $grossDelta);
        $this->assertGreaterThan(0, $taxDelta);
        $this->assertSame($grossDelta - ($revised->employeeDeductions->minor() - $original->employeeDeductions->minor()), $netDelta);
        $this->assertSame(
            $original->tax->minor() + $taxDelta,
            $revised->tax->minor(),
            'Original plus correction delta must exactly reconcile to the revised tax.',
        );
    }

    private function calculate(
        int $grossCents,
        int $year,
        int $month,
        int $specialDeductionsCents,
        ?YtdTaxSnapshot $ytd = null,
    ): \App\Modules\Payroll\Domain\PayrollCalculationResult {
        $special = $specialDeductionsCents === 0 ? [] : [
            new DeductionLine(
                itemCode: 'SPECIAL_DEDUCTION',
                label: 'Special additional deduction',
                amount: Money::fromMinor($specialDeductionsCents),
                source: new PayrollLineSource('deduction_declaration', 'DEMO-1'),
                sortOrder: 900,
            ),
        ];

        return (new PayrollCalculator)->calculate($this->makeInput(
            baseSalaryCents: $grossCents,
            attendance: $this->fullAttendance(),
            period: new PayrollPeriod($year, $month),
            context: $this->context(),
            specialDeductions: $special,
            ytd: $ytd,
        ));
    }
}
