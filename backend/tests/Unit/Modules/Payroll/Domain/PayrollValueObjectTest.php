<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\AttendanceSummary;
use App\Modules\Payroll\Domain\HousingFundRule;
use App\Modules\Payroll\Domain\PayrollContext;
use App\Modules\Payroll\Domain\PayrollPeriod;
use App\Modules\Payroll\Domain\ProrationDivisor;
use App\Modules\Payroll\Domain\RoundingConfig;
use App\Modules\Payroll\Domain\SocialInsuranceRule;
use App\Modules\Payroll\Domain\TaxBracket;
use App\Shared\Money\Percentage;
use App\Shared\Money\RoundingMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayrollValueObjectTest extends TestCase
{
    public function test_period_rejects_invalid_year_and_month(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PayrollPeriod(2026, 13);
    }

    public function test_rounding_config_rejects_unsupported_money_scale(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoundingConfig(RoundingMode::HALF_UP, 3);
    }

    public function test_context_rejects_invalid_timezone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PayrollContext(
            legalEntityId: 1,
            currency: 'CNY',
            timezone: 'Mars/Olympus',
            rounding: new RoundingConfig(RoundingMode::HALF_UP),
            prorationDivisor: ProrationDivisor::ANNUAL_DAYS_365,
        );
    }

    public function test_context_rejects_lowercase_currency_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PayrollContext(
            legalEntityId: 1,
            currency: 'cny',
            timezone: 'Asia/Shanghai',
            rounding: new RoundingConfig(RoundingMode::HALF_UP),
            prorationDivisor: ProrationDivisor::ANNUAL_DAYS_365,
        );
    }

    public function test_attendance_rejects_overlapping_minutes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AttendanceSummary(480, 480, 60, 0, 0);
    }

    public function test_attendance_accepts_exact_scheduled_minutes(): void
    {
        $summary = new AttendanceSummary(480, 420, 60, 0, 120);

        $this->assertSame(480, $summary->paidMinutes());
        $this->assertSame(0, $summary->unworkedMinutes());
    }

    public function test_si_and_hf_rules_reject_inverted_bounds(): void
    {
        foreach ([
            fn () => new SocialInsuranceRule('DEMO', 100, 99, Percentage::fromPercent('1'), Percentage::fromPercent('1')),
            fn () => new HousingFundRule('DEMO', 100, 99, Percentage::fromPercent('1'), Percentage::fromPercent('1')),
        ] as $factory) {
            try {
                $factory();
                $this->fail('Invalid base bounds should be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_tax_bracket_rejects_zero_width_and_negative_rate_data(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaxBracket(100, 100, Percentage::fromPercent('1'), 0);
    }

    public function test_tax_bracket_rejects_negative_lower_bound(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaxBracket(-1, null, Percentage::fromPercent('1'), 0);
    }
}
