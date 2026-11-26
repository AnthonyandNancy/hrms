<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollScheme;
use App\Modules\Payroll\Services\TaxParameterResolver;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\PeopleScenario;
use Tests\TestCase;

/**
 * `payroll:seed-demo-run` gives the reconciliation and parallel-run gates real
 * calculated rows instead of "no calculated payroll runs matched". It must stop
 * at CALCULATED and never auto-record review/approval/publish, which are
 * separation-of-duties steps.
 */
final class SeedDemoPayrollRunCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_without_statutory_parameters(): void
    {
        [$employment] = $this->fixture(salaryCents: 1_000_000);

        $this->artisan('payroll:seed-demo-run', [
            '--period' => '2026-11',
        ])->assertFailed();

        $this->assertSame(0, PayrollRun::query()->count());
    }

    public function test_it_refuses_without_a_salaried_employment(): void
    {
        $this->seedParameters();
        $this->fixture(salaryCents: null);

        $this->artisan('payroll:seed-demo-run', [
            '--period' => '2026-11',
        ])->assertFailed();

        $this->assertSame(0, PayrollRun::query()->count());
    }

    public function test_it_rejects_a_malformed_period(): void
    {
        $this->artisan('payroll:seed-demo-run', [
            '--period' => '2026-13',
        ])->assertExitCode(2);
    }

    public function test_it_creates_a_calculated_run_and_never_auto_approves(): void
    {
        $this->seedParameters();
        [$employment] = $this->fixture(salaryCents: 1_200_000);

        try {
            $exit = Artisan::call('payroll:seed-demo-run', [
                '--period' => '2026-11',
                '--actor' => 1,
            ]);
        } catch (\Throwable $exception) {
            $reasons = \App\Modules\Payroll\Models\PayrollRunEmployee::query()
                ->pluck('failure_reason')
                ->filter()
                ->unique()
                ->implode(' | ');
            $snapshotCount = \App\Modules\Payroll\Models\PayrollInputSnapshot::query()->count();
            $runRows = PayrollRun::query()->count();
            $this->fail('seed-demo-run threw: '.$exception->getMessage()
                .' | reasons: '.$reasons
                .' | snapshots: '.$snapshotCount
                .' | runs: '.$runRows);
        }

        $this->assertSame(0, $exit);

        $run = PayrollRun::query()->where('period', '2026-11')->firstOrFail();

        $this->assertSame('CALCULATED', $run->status->value);
        $this->assertGreaterThan(0, (int) $run->gross_cents);
        $this->assertGreaterThan(0, (int) $run->net_cents);
        $this->assertNull($run->approved_at ?? null);

        // Only the intending preparer appears; no reviewer/approver evidence.
        $this->assertDatabaseMissing('payroll_runs', [
            'id' => $run->id,
            'status' => 'APPROVED',
        ]);
        $this->assertDatabaseMissing('payroll_runs', [
            'id' => $run->id,
            'status' => 'PUBLISHED',
        ]);

        // The salary comes from the employment terms, not a scheme default.
        $this->assertSame(
            (int) EmploymentTerms::query()
                ->where('employment_id', $employment->id)
                ->whereNotNull('base_salary_minor')
                ->value('base_salary_minor'),
            (int) $run->gross_cents,
        );
    }

    public function test_re_running_is_idempotent(): void
    {
        $this->seedParameters();
        $this->fixture(salaryCents: 1_200_000);

        Artisan::call('payroll:seed-demo-run', [
            '--period' => '2026-11',
        ]);
        Artisan::call('payroll:seed-demo-run', [
            '--period' => '2026-11',
        ]);

        $this->assertSame(1, PayrollRun::query()->where('period', '2026-11')->count());
    }

    /**
     * @return array{0: Employment}
     */
    private function fixture(?int $salaryCents): array
    {
        $scenario = new PeopleScenario(app(\App\Shared\Time\Clock::class));
        $unit = $scenario->orgUnit('PAY-UNIT');
        $employment = $scenario->employment($scenario->person(), 'PAY-0001');
        $scenario->assignment($employment, $unit);
        if ($salaryCents === null) {
            $scenario->terms($employment, 1);

            return [$employment];
        }

        // Employment terms are append-only (ADR-0002), so salary is set on the
        // version created for the fixture rather than mutating it afterwards.
        $scenario->terms($employment, 1, [
            'base_salary_minor' => $salaryCents,
            'salary_currency' => 'CNY',
        ]);

        return [$employment];
    }

    private function seedParameters(): void
    {
        $parameters = app(TaxParameterResolver::class);

        $parameters->createVersion([
            'parameter_set' => 'TAX',
            'version' => 'DEMO-TAX-2026',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'THRESHOLD_CENTS' => 500_000,
            'brackets' => [
                ['lower_bound_cents' => 0, 'upper_bound_cents' => 1_000_000, 'rate_bps' => 3, 'quick_deduction_cents' => 0],
                ['lower_bound_cents' => 1_000_000, 'upper_bound_cents' => 3_000_000, 'rate_bps' => 10, 'quick_deduction_cents' => 70_000],
                ['lower_bound_cents' => 3_000_000, 'upper_bound_cents' => null, 'rate_bps' => 20, 'quick_deduction_cents' => 370_000],
            ],
        ], 99, true, 'DEMO-SYNTHETIC-NOT-LEGAL-ADVICE');

        foreach (['SOCIAL_INSURANCE', 'HOUSING_FUND'] as $set) {
            $parameters->createVersion([
                'parameter_set' => $set,
                'version' => 'DEMO-'.$set.'-2026',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
                'MIN_BASE_CENTS' => 400_000,
                'MAX_BASE_CENTS' => 3_000_000,
                'EMPLOYEE_RATE' => $set === 'SOCIAL_INSURANCE' ? '8.000000' : '7.000000',
                'EMPLOYER_RATE' => $set === 'SOCIAL_INSURANCE' ? '16.000000' : '7.000000',
            ], 99, true, 'DEMO-SYNTHETIC-NOT-LEGAL-ADVICE');
        }

        PayrollScheme::query()->create([
            'code' => 'DEMO-MONTHLY',
            'name' => 'Synthetic demo monthly scheme',
            'frequency' => 'MONTHLY',
            'currency' => 'CNY',
            'version' => 1,
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'base_salary_cents' => 0,
            // A demo fixture with a low SI/HF floor can legitimately produce a
            // negative net when attendance is missing; the calculator treats
            // that as a hard failure unless the scheme allows it.
            'allow_negative_net' => true,
        ]);
    }
}
