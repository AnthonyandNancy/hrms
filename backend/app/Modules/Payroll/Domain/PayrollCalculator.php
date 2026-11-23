<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Decimal;
use App\Shared\Money\Money;
use App\Shared\Money\Percentage;
use App\Shared\Money\RoundingMode;

/**
 * Pure gross-to-net calculator. No database, HTTP, global clock, or binary
 * imprecise numeric type is used. Statutory parameters are injected versions.
 *
 * Calculation order:
 *   1 base salary
 *   2 attendance/leave proration
 *   3 allowances and one-time bonuses
 *   4 overtime
 *   5 gross
 *   6 social-insurance base clamp and employee/employer contribution
 *   7 housing-fund base clamp and employee/employer contribution
 *   8 taxable income
 *   9 cumulative withholding
 *  10 other deductions/manual adjustments
 *  11 net
 *  12 employer cost
 */
final class PayrollCalculator
{
    private const MINUTES_PER_HOUR = 60;

    private const MINUTES_PER_STANDARD_DAY = 480;

    private const MONTHS_PER_YEAR = 12;

    public function calculate(PayrollCalculationInput $input): PayrollCalculationResult
    {
        $currency = $input->context->currency;
        $rounding = $input->context->rounding;
        $lines = [];
        $sort = 10;

        // 1-2: salary and absence proration. Proration is based on unworked
        // minutes against the standard 8-hour day, using the context's
        // versioned daily-rate divisor. Round once at the cents boundary.
        $proratedBase = $this->proratedBaseCents($input);
        $lines[] = new PayslipLineDraft(
            itemCode: 'BASE_SALARY',
            category: PayslipLineCategory::EARNING,
            amount: Money::fromMinor($proratedBase, $currency),
            source: new PayrollLineSource($input->salarySourceType, $input->salarySourceId),
            sortOrder: $sort,
            quantityScaled: $input->attendance->paidMinutes(),
            unit: 'MINUTE',
            rateScaled: $this->minuteRateScaled($proratedBase, $input->attendance->paidMinutes()),
        );
        $sort += 10;

        // 3: approved allowances and one-time bonuses already supplied in
        // integer cents by their source facts.
        foreach ($input->allowances as $line) {
            $lines[] = $line->toPayslipLine($sort);
            $sort += 10;
        }

        foreach ($input->oneTimeBonuses as $line) {
            $lines[] = $line->toPayslipLine($sort);
            $sort += 10;
        }

        // 4: overtime computed from exact minutes / 60 and the parameterised
        // multiplier. Round once at the cents boundary.
        if ($input->attendance->overtimeMinutes > 0) {
            $lines[] = new PayslipLineDraft(
                itemCode: 'OVERTIME',
                category: PayslipLineCategory::EARNING,
                amount: Money::fromMinor($this->overtimeCents($input), $currency),
                source: new PayrollLineSource($input->attendanceSourceType, $input->attendanceSourceId),
                sortOrder: $sort,
                quantityScaled: $input->attendance->overtimeMinutes,
                unit: 'MINUTE',
                rateScaled: $this->overtimeMinuteRateScaled($input),
            );
            $sort += 10;
        }

        // Positive manual adjustments are approved earnings and belong to
        // gross. Negative ones remain deductions and do not reduce gross.
        foreach ($input->manualAdjustments as $adjustment) {
            if ($adjustment->isEarning()) {
                $lines[] = $adjustment->toPayslipLine($sort);
                $sort += 10;
            }
        }

        $grossCents = 0;
        foreach ($lines as $line) {
            if ($line->category->isEarning()) {
                $grossCents = $this->checkedAdd($grossCents, $line->amount->minor(), 'gross');
            }
        }

        // 6: social insurance uses a clamped base and separate employee and
        // employer rates. Contributions round once at the cents boundary.
        $siBase = $this->clamp(
            $grossCents,
            $input->socialInsurance->minBaseCents,
            $input->socialInsurance->maxBaseCents,
        );
        $employeeSi = $this->percentageCents($siBase, $input->socialInsurance->employeeRate, $rounding->mode);
        $employerSi = $this->percentageCents($siBase, $input->socialInsurance->employerRate, $rounding->mode);

        // 7: housing fund has an independent base clamp and rates.
        $hfBase = $this->clamp(
            $grossCents,
            $input->housingFund->minBaseCents,
            $input->housingFund->maxBaseCents,
        );
        $employeeHf = $this->percentageCents($hfBase, $input->housingFund->employeeRate, $rounding->mode);
        $employerHf = $this->percentageCents($hfBase, $input->housingFund->employerRate, $rounding->mode);

        // 8: current-period taxable income, floored at zero. Threshold and
        // special deductions apply to the current period, while cumulative
        // taxable income comes from the YTD snapshot.
        $specialDeductionCents = $this->sumDeductions($input->specialDeductions);
        $taxableBeforeThreshold = $this->checkedAdd(
            $this->checkedAdd(
                $employeeSi,
                $employeeHf,
                'taxable income deductions',
            ),
            $this->checkedAdd(
                $specialDeductionCents,
                $input->taxTable->thresholdCents,
                'taxable income deductions',
            ),
            'taxable income deductions',
        );
        $periodTaxable = max(0, $this->checkedDifference($grossCents, $taxableBeforeThreshold, 'taxable income'));

        // 9: resident cumulative withholding. Lower cumulative liability than
        // the amount already withheld yields zero current tax and a negative
        // carry-forward; there is no in-year refund.
        $cumulativeTaxable = $this->checkedAdd(
            $input->ytd->cumulativeTaxableCents,
            $periodTaxable,
            'cumulative taxable income',
        );
        $cumulativeTax = $input->taxTable->taxOn($cumulativeTaxable, $rounding->mode);
        $difference = $this->checkedDifference(
            $cumulativeTax,
            $input->ytd->taxWithheldCents,
            'cumulative tax difference',
        );
        $currentTax = max(0, $difference);
        $carryForward = min(0, $difference);

        $taxDetail = new CumulativeTaxDetail(
            taxTableVersion: $input->taxTable->version,
            taxYear: $input->period->year,
            ytdTaxableCents: $input->ytd->cumulativeTaxableCents,
            currentTaxableCents: $periodTaxable,
            cumulativeTaxableCents: $cumulativeTaxable,
            thresholdCents: $input->taxTable->thresholdCents,
            specialDeductionsCents: $specialDeductionCents,
            ytdTaxWithheldCents: $input->ytd->taxWithheldCents,
            cumulativeTaxCents: $cumulativeTax,
            currentTaxCents: $currentTax,
            carryForwardCents: $carryForward,
        );

        // 10: deduction lines in documented order: statutory deductions,
        // other deductions, then negative manual adjustments.
        if ($employeeSi > 0) {
            $lines[] = $this->statutoryLine(
                'SOCIAL_INSURANCE_EMPLOYEE',
                PayslipLineCategory::DEDUCTION,
                $employeeSi,
                'statutory_parameter',
                $input->socialInsurance->version,
                $sort,
                $siBase,
                $input->socialInsurance->employeeRate->scaled(),
                $currency,
            );
            $sort += 10;
        }

        if ($employeeHf > 0) {
            $lines[] = $this->statutoryLine(
                'HOUSING_FUND_EMPLOYEE',
                PayslipLineCategory::DEDUCTION,
                $employeeHf,
                'statutory_parameter',
                $input->housingFund->version,
                $sort,
                $hfBase,
                $input->housingFund->employeeRate->scaled(),
                $currency,
            );
            $sort += 10;
        }

        if ($currentTax > 0) {
            $lines[] = $this->statutoryLine(
                'INDIVIDUAL_INCOME_TAX',
                PayslipLineCategory::DEDUCTION,
                $currentTax,
                'tax_table',
                $input->taxTable->version,
                $sort,
                0,
                0,
                $currency,
            );
            $sort += 10;
        }

        foreach ($input->otherDeductions as $line) {
            $lines[] = $line->toPayslipLine($sort);
            $sort += 10;
        }

        foreach ($input->manualAdjustments as $adjustment) {
            if (! $adjustment->isEarning()) {
                $lines[] = $adjustment->toPayslipLine($sort);
                $sort += 10;
            }
        }

        if ($employerSi > 0) {
            $lines[] = $this->statutoryLine(
                'SOCIAL_INSURANCE_EMPLOYER',
                PayslipLineCategory::EMPLOYER_COST,
                $employerSi,
                'statutory_parameter',
                $input->socialInsurance->version,
                $sort,
                $siBase,
                $input->socialInsurance->employerRate->scaled(),
                $currency,
            );
            $sort += 10;
        }

        if ($employerHf > 0) {
            $lines[] = $this->statutoryLine(
                'HOUSING_FUND_EMPLOYER',
                PayslipLineCategory::EMPLOYER_COST,
                $employerHf,
                'statutory_parameter',
                $input->housingFund->version,
                $sort,
                $hfBase,
                $input->housingFund->employerRate->scaled(),
                $currency,
            );
        }

        $negativeManualAdjustments = $this->negativeManualAdjustmentCents($input);
        $otherDeductionCents = $this->checkedAdd(
            $this->sumDeductions($input->otherDeductions),
            $negativeManualAdjustments,
            'other deductions',
        );
        $employeeDeductions = $this->checkedAdd(
            $this->checkedAdd($employeeSi, $employeeHf, 'employee deductions'),
            $this->checkedAdd($currentTax, $otherDeductionCents, 'employee deductions'),
            'employee deductions',
        );
        $netCents = $this->checkedDifference($grossCents, $employeeDeductions, 'net');
        $employerCost = $this->checkedAdd(
            $this->checkedAdd($grossCents, $employerSi, 'employer cost'),
            $employerHf,
            'employer cost',
        );
        $warnings = [];

        // 11-12: net and employer cost. A negative net is a hard failure unless
        // the legal entity's configuration explicitly allows it. If allowed,
        // the exact negative amount is returned and flagged for review.
        if ($netCents < 0) {
            if (! $input->context->allowNegativeNet) {
                throw new \InvalidArgumentException('Net pay cannot be negative for this payroll context.');
            }

            $warnings[] = 'NET_BELOW_ZERO';
        }

        return new PayrollCalculationResult(
            gross: Money::fromMinor($grossCents, $currency),
            taxableIncome: Money::fromMinor($periodTaxable, $currency),
            employeeSocialInsurance: Money::fromMinor($employeeSi, $currency),
            employeeHousingFund: Money::fromMinor($employeeHf, $currency),
            tax: Money::fromMinor($currentTax, $currency),
            otherDeductions: Money::fromMinor($otherDeductionCents, $currency),
            employeeDeductions: Money::fromMinor($employeeDeductions, $currency),
            employerSocialInsurance: Money::fromMinor($employerSi, $currency),
            employerHousingFund: Money::fromMinor($employerHf, $currency),
            employerCost: Money::fromMinor($employerCost, $currency),
            net: Money::fromMinor($netCents, $currency),
            cumulativeTax: $taxDetail,
            lines: $lines,
            warnings: $warnings,
        );
    }

    private function proratedBaseCents(PayrollCalculationInput $input): int
    {
        $unworkedMinutes = $input->attendance->unworkedMinutes();
        if ($unworkedMinutes === 0) {
            return $input->baseSalaryCents;
        }

        $dailyRate = $this->dailyRate(
            Decimal::fromScaledInt($input->baseSalaryCents, 2),
            $input->context->prorationDivisor,
            $input->context->rounding->mode,
        );
        $deduction = $dailyRate
            ->multiply(Decimal::fromScaledInt($unworkedMinutes, 0))
            ->divide(Decimal::fromString((string) self::MINUTES_PER_STANDARD_DAY), 6, $input->context->rounding->mode);

        return max(
            0,
            Decimal::fromScaledInt($input->baseSalaryCents, 2)
                ->subtract($deduction)
                ->toScaledInt(2, $input->context->rounding->mode),
        );
    }

    private function overtimeCents(PayrollCalculationInput $input): int
    {
        $daily = $this->dailyRate(
            Decimal::fromScaledInt($input->baseSalaryCents, 2),
            $input->context->prorationDivisor,
            $input->context->rounding->mode,
        );
        $hourly = $daily->divide(
            Decimal::fromString('8'),
            6,
            $input->context->rounding->mode,
        );
        $amount = $hourly
            ->multiply(Decimal::fromScaledInt($input->attendance->overtimeMinutes, 0))
            ->divide(Decimal::fromString((string) self::MINUTES_PER_HOUR), 6, $input->context->rounding->mode)
            ->multiply($input->overtimeMultiplier);

        return $amount->toScaledInt(2, $input->context->rounding->mode);
    }

    private function overtimeMinuteRateScaled(PayrollCalculationInput $input): int
    {
        $daily = $this->dailyRate(
            Decimal::fromScaledInt($input->baseSalaryCents, 2),
            $input->context->prorationDivisor,
            $input->context->rounding->mode,
        );

        return $daily
            ->divide(Decimal::fromString((string) self::MINUTES_PER_STANDARD_DAY), 6, $input->context->rounding->mode)
            ->multiply($input->overtimeMultiplier)
            ->toScaledInt(6);
    }

    private function dailyRate(
        Decimal $monthlySalary,
        ProrationDivisor $divisor,
        RoundingMode $mode,
    ): Decimal {
        return match ($divisor) {
            ProrationDivisor::ANNUAL_DAYS_365 => $monthlySalary
                ->multiply(Decimal::fromString((string) self::MONTHS_PER_YEAR))
                ->divide(Decimal::fromString('365'), 6, $mode),
            ProrationDivisor::FIXED_21_75 => $monthlySalary->divide(
                Decimal::fromString('21.75'),
                6,
                $mode,
            ),
        };
    }

    private function percentageCents(int $baseCents, Percentage $rate, RoundingMode $mode): int
    {
        return Decimal::fromScaledInt($baseCents, 2)
            ->multiply($rate->toRatio())
            ->toScaledInt(2, $mode);
    }

    private function minuteRateScaled(int $amountCents, int $minutes): int
    {
        if ($minutes === 0) {
            return 0;
        }

        return Decimal::fromScaledInt($amountCents, 2)
            ->divide(Decimal::fromScaledInt($minutes, 0), 6)
            ->toScaledInt(6);
    }

    private function clamp(int $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, $value));
    }

    /**
     * @param  list<DeductionLine>  $deductions
     */
    private function sumDeductions(array $deductions): int
    {
        $total = 0;
        foreach ($deductions as $line) {
            $total = $this->checkedAdd($total, $line->amount->minor(), 'deductions');
        }

        return $total;
    }

    private function negativeManualAdjustmentCents(PayrollCalculationInput $input): int
    {
        $total = 0;
        foreach ($input->manualAdjustments as $adjustment) {
            if (! $adjustment->isEarning()) {
                $total = $this->checkedAdd($total, $adjustment->absoluteAmount()->minor(), 'manual adjustments');
            }
        }

        return $total;
    }

    private function checkedAdd(int $left, int $right, string $label): int
    {
        $result = bcadd((string) $left, (string) $right, 0);
        if (bccomp($result, (string) PHP_INT_MAX, 0) > 0
            || bccomp($result, (string) PHP_INT_MIN, 0) < 0) {
            throw new \InvalidArgumentException("Payroll {$label} exceeds the native integer range.");
        }

        return (int) $result;
    }

    private function checkedDifference(int $left, int $right, string $label): int
    {
        $result = bcsub((string) $left, (string) $right, 0);
        if (bccomp($result, (string) PHP_INT_MAX, 0) > 0
            || bccomp($result, (string) PHP_INT_MIN, 0) < 0) {
            throw new \InvalidArgumentException("Payroll {$label} exceeds the native integer range.");
        }

        return (int) $result;
    }

    private function statutoryLine(
        string $itemCode,
        PayslipLineCategory $category,
        int $amountCents,
        string $sourceType,
        string $sourceId,
        int $sortOrder,
        int $quantityScaled,
        int $rateScaled,
        string $currency,
    ): PayslipLineDraft {
        return new PayslipLineDraft(
            itemCode: $itemCode,
            category: $category,
            amount: Money::fromMinor($amountCents, $currency),
            source: new PayrollLineSource($sourceType, $sourceId),
            sortOrder: $sortOrder,
            quantityScaled: $quantityScaled,
            unit: $quantityScaled > 0 ? 'CENT' : 'MONTH',
            rateScaled: $rateScaled,
            taxable: false,
        );
    }
}
