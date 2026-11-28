<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Decimal;
use App\Shared\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Complete immutable input to a payroll calculation. Earning period, posting
 * period, pay date, and cutoff are intentionally separate fields.
 */
final readonly class PayrollCalculationInput
{
    /**
     * @param  list<EarningLine>  $allowances
     * @param  list<EarningLine>  $oneTimeBonuses
     * @param  list<ManualAdjustment>  $manualAdjustments
     * @param  list<DeductionLine>  $specialDeductions
     * @param  list<DeductionLine>  $otherDeductions
     * @param  list<EmployerCostLine>  $otherEmployerCosts
     */
    public function __construct(
        public int $employmentId,
        public PayrollPeriod $period,
        public PayrollPeriod $postingPeriod,
        public DateTimeImmutable $payDate,
        public DateTimeImmutable $cutoffAt,
        public PayrollContext $context,
        public int $baseSalaryCents,
        public AttendanceSummary $attendance,
        public YtdTaxSnapshot $ytd,
        public TaxTable $taxTable,
        public SocialInsuranceRule $socialInsurance,
        public HousingFundRule $housingFund,
        public Decimal $overtimeMultiplier,
        public string $salarySourceType,
        public string $salarySourceId,
        public string $attendanceSourceType,
        public string $attendanceSourceId,
        public array $allowances = [],
        public array $oneTimeBonuses = [],
        public array $manualAdjustments = [],
        public array $specialDeductions = [],
        public array $otherDeductions = [],
        public array $otherEmployerCosts = [],
    ) {
        if ($employmentId < 1) {
            throw new InvalidArgumentException('Employment id must be positive.');
        }

        if ($baseSalaryCents < 0) {
            throw new InvalidArgumentException('Base salary cannot be negative.');
        }

        if ($ytd->taxYear !== $period->year) {
            throw new InvalidArgumentException('YTD snapshot must belong to the earnings period tax year.');
        }

        if ($overtimeMultiplier->compare(Decimal::fromString('0')) < 0) {
            throw new InvalidArgumentException('Overtime multiplier cannot be negative.');
        }

        if (trim($salarySourceType) === '' || trim($salarySourceId) === '') {
            throw new InvalidArgumentException('Salary source type and id are mandatory.');
        }

        if (trim($attendanceSourceType) === '' || trim($attendanceSourceId) === '') {
            throw new InvalidArgumentException('Attendance source type and id are mandatory.');
        }

        foreach ($allowances as $line) {
            if (! $line instanceof EarningLine) {
                throw new InvalidArgumentException('Allowances must be EarningLine instances.');
            }
            $this->assertCurrency($line->amount);
        }
        foreach ($oneTimeBonuses as $line) {
            if (! $line instanceof EarningLine) {
                throw new InvalidArgumentException('One-time bonuses must be EarningLine instances.');
            }
            $this->assertCurrency($line->amount);
        }
        foreach ($manualAdjustments as $line) {
            if (! $line instanceof ManualAdjustment) {
                throw new InvalidArgumentException('Manual adjustments must be ManualAdjustment instances.');
            }
            $this->assertCurrency($line->amount);
        }
        foreach ($specialDeductions as $line) {
            if (! $line instanceof DeductionLine) {
                throw new InvalidArgumentException('Special deductions must be DeductionLine instances.');
            }
            $this->assertCurrency($line->amount);
        }
        foreach ($otherDeductions as $line) {
            if (! $line instanceof DeductionLine) {
                throw new InvalidArgumentException('Other deductions must be DeductionLine instances.');
            }
            $this->assertCurrency($line->amount);
        }
        foreach ($otherEmployerCosts as $line) {
            if (! $line instanceof EmployerCostLine) {
                throw new InvalidArgumentException('Other employer costs must be EmployerCostLine instances.');
            }
            $this->assertCurrency($line->amount);
        }
    }

    private function assertCurrency(Money $money): void
    {
        if ($money->currency() !== $this->context->currency) {
            throw new InvalidArgumentException('All payroll input amounts must use the payroll currency.');
        }
    }
}
