<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\AttendanceSummary;
use App\Modules\Payroll\Domain\HousingFundRule;
use App\Modules\Payroll\Domain\PayrollCalculationInput;
use App\Modules\Payroll\Domain\PayrollContext;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Modules\Payroll\Domain\ProrationDivisor;
use App\Modules\Payroll\Domain\RoundingConfig;
use App\Modules\Payroll\Domain\SocialInsuranceRule;
use App\Modules\Payroll\Domain\TaxBracket;
use App\Modules\Payroll\Domain\TaxTable;
use App\Modules\Payroll\Domain\YtdTaxSnapshot;
use App\Shared\Money\Decimal;
use App\Shared\Money\Percentage;
use App\Shared\Money\RoundingMode;
use DateTimeImmutable;

trait PayrollTestFixtures
{
    protected function context(
        RoundingMode $roundingMode = RoundingMode::HALF_UP,
        ProrationDivisor $divisor = ProrationDivisor::ANNUAL_DAYS_365,
        bool $allowNegativeNet = false,
    ): PayrollContext {
        return new PayrollContext(
            currency: 'CNY',
            timezone: 'Asia/Shanghai',
            rounding: new RoundingConfig($roundingMode, 2),
            prorationDivisor: $divisor,
            allowNegativeNet: $allowNegativeNet,
        );
    }

    protected function fullAttendance(int $overtimeMinutes = 0): AttendanceSummary
    {
        return new AttendanceSummary(
            scheduledMinutes: AttendanceSummary::STANDARD_MONTH_MINUTES,
            workedMinutes: AttendanceSummary::STANDARD_MONTH_MINUTES,
            paidLeaveMinutes: 0,
            unpaidLeaveMinutes: 0,
            overtimeMinutes: $overtimeMinutes,
        );
    }

    protected function zeroSocialInsurance(): SocialInsuranceRule
    {
        return new SocialInsuranceRule(
            version: 'DEMO-SI-zero',
            minBaseCents: 0,
            maxBaseCents: 100000000,
            employeeRate: Percentage::fromPercent('0'),
            employerRate: Percentage::fromPercent('0'),
        );
    }

    protected function zeroHousingFund(): HousingFundRule
    {
        return new HousingFundRule(
            version: 'DEMO-HF-zero',
            minBaseCents: 0,
            maxBaseCents: 100000000,
            employeeRate: Percentage::fromPercent('0'),
            employerRate: Percentage::fromPercent('0'),
        );
    }

    protected function noTaxTable(): TaxTable
    {
        return new TaxTable(
            version: 'DEMO-TAX-zero',
            thresholdCents: 100000000,
            brackets: [
                new TaxBracket(
                    lowerCents: 0,
                    upperCents: null,
                    rate: Percentage::fromPercent('0'),
                    quickDeductionCents: 0,
                ),
            ],
        );
    }

    protected function standardTaxTable(): TaxTable
    {
        return new TaxTable(
            version: 'DEMO-TAX-2026-v1',
            thresholdCents: 500000,
            brackets: [
                new TaxBracket(
                    lowerCents: 0,
                    upperCents: 300000,
                    rate: Percentage::fromPercent('3'),
                    quickDeductionCents: 0,
                ),
                new TaxBracket(
                    lowerCents: 300000,
                    upperCents: null,
                    rate: Percentage::fromPercent('10'),
                    quickDeductionCents: 21000,
                ),
            ],
        );
    }

    protected function emptyYtd(int $taxYear): YtdTaxSnapshot
    {
        return new YtdTaxSnapshot(taxYear: $taxYear);
    }

    /**
     * @param  list<\App\Modules\Payroll\Domain\EarningLine>  $allowances
     * @param  list<\App\Modules\Payroll\Domain\EarningLine>  $bonuses
     * @param  list<\App\Modules\Payroll\Domain\ManualAdjustment>  $manualAdjustments
     * @param  list<\App\Modules\Payroll\Domain\DeductionLine>  $specialDeductions
     * @param  list<\App\Modules\Payroll\Domain\DeductionLine>  $otherDeductions
     */
    protected function makeInput(
        int $baseSalaryCents,
        AttendanceSummary $attendance,
        PayrollPeriod $period,
        PayrollContext $context,
        array $allowances = [],
        array $bonuses = [],
        array $manualAdjustments = [],
        array $specialDeductions = [],
        array $otherDeductions = [],
        ?YtdTaxSnapshot $ytd = null,
        ?TaxTable $taxTable = null,
        ?SocialInsuranceRule $socialInsurance = null,
        ?HousingFundRule $housingFund = null,
        ?Decimal $overtimeMultiplier = null,
    ): PayrollCalculationInput {
        return new PayrollCalculationInput(
            employmentId: 42,
            period: $period,
            postingPeriod: new PayrollPeriod($period->year, $period->month),
            payDate: new DateTimeImmutable('2026-02-05T09:00:00+08:00'),
            cutoffAt: new DateTimeImmutable('2026-01-31T18:00:00+08:00'),
            context: $context,
            baseSalaryCents: $baseSalaryCents,
            attendance: $attendance,
            ytd: $ytd ?? $this->emptyYtd($period->year),
            taxTable: $taxTable ?? $this->standardTaxTable(),
            socialInsurance: $socialInsurance ?? $this->zeroSocialInsurance(),
            housingFund: $housingFund ?? $this->zeroHousingFund(),
            overtimeMultiplier: $overtimeMultiplier ?? Decimal::fromString('1.5'),
            salarySourceType: 'employment_terms',
            salarySourceId: '1001',
            attendanceSourceType: 'attendance_summary',
            attendanceSourceId: '2026-01:42',
            allowances: $allowances,
            oneTimeBonuses: $bonuses,
            manualAdjustments: $manualAdjustments,
            specialDeductions: $specialDeductions,
            otherDeductions: $otherDeductions,
        );
    }
}
