<?php

declare(strict_types=1);

namespace Tests\Integration\Payroll;

use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use Tests\Support\PayrollTestCase;

/**
 * People -> Payroll integration. Terms version selection is as-of the earning
 * period end, so a future raise does not leak into an earlier month and a
 * mid-month termination prorates.
 */
final class PeopleToPayrollIntegrationTest extends PayrollTestCase
{
    public function test_as_of_terms_excludes_future_raise(): void
    {
        $base = $this->seedPayrollBase(1_000_000);

        // A future raise effective 2026-07-01 with version 2.
        $employment = \App\Modules\People\Models\Employment::query()->whereKey($base['employment_id'])->firstOrFail();
        $current = \App\Modules\People\Models\EmploymentTerms::query()
            ->where('employment_id', $employment->id)
            ->firstOrFail();
        $current->forceFill(['effective_to' => '2026-07-01'])->save();

        \App\Modules\People\Models\EmploymentTerms::query()->create([
            'tenant_id' => $this->tenantId,
            'employment_id' => $employment->id,
            'version' => 2,
            'effective_from' => '2026-07-01',
            'effective_to' => '9999-12-31',
            'probation_end_date' => null,
            'work_location' => 'Shanghai',
            'work_schedule' => 'STANDARD',
            'salary_scheme_code' => 'SCHEME-A',
            'base_salary_cents' => 2_000_000,
            'salary_currency' => 'CNY',
            'notice_period_days' => 30,
            'confidentiality' => true,
            'non_compete' => false,
            'signed_at' => '2026-06-15 00:00:00',
            'source_type' => 'TEST',
            'recorded_at' => $this->clock->now(),
        ]);

        $juneGross = $this->calculateGross($base, '2026-06');
        $julyGross = $this->calculateGross($base, '2026-07');

        $this->assertSame(1_000_000, $juneGross);
        $this->assertSame(2_000_000, $julyGross);
    }

    public function test_mid_month_termination_prorates_base_salary(): void
    {
        $base = $this->seedPayrollBase(1_000_000);
        $employmentId = (int) $base['employment_id'];

        // The employee leaves after 15 days. Time reports only the scheduled
        // minutes that were actually due before termination.
        \App\Modules\Time\Models\AttendanceDay::query()->create([
            'tenant_id' => $this->tenantId,
            'employment_id' => $employmentId,
            'business_date' => '2026-08-03',
            'shift_template_id' => null,
            'scheduled_minutes' => 10_440,
            'worked_minutes' => 5_220,
            'overtime_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'absence_minutes' => 5_220,
            'status' => 'TERMINATED',
            'rule_version' => 'DEMO',
            'source_hash' => hash('sha256', 'termination-proration'),
            'calculated_at' => $this->clock->now(),
        ]);

        $gross = $this->calculateGross($base, '2026-08');
        $this->assertLessThan(1_000_000, $gross);
        $this->assertGreaterThan(0, $gross);
    }

    /**
     * @param  array{scheme: \App\Modules\Payroll\Models\PayrollScheme, employment_id: int}  $base
     */
    private function calculateGross(array $base, string $period): int
    {
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun($this->tenantId, 10, ['scheme_id' => (int) $base['scheme']->id, 'period' => $period]);
        $run = $runs->lockInputs($this->tenantId, (int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        return (int) $run->refresh()->gross_cents;
    }
}
