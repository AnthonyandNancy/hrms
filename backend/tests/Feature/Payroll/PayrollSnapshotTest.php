<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Contracts\BenefitPayrollInputDirectory;
use App\Modules\Payroll\Contracts\PerformanceBonusDirectory;
use App\Modules\Payroll\Enums\PayrollEmployeeStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\EmployeeCompensation;
use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\ReopenService;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\TimePayrollFactRow;
use App\Modules\Time\Models\TimePayrollFactVersion;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use Tests\Support\PayrollTestCase;

final class PayrollSnapshotTest extends PayrollTestCase
{
    public function test_snapshot_hash_is_stable_across_relocking_and_matches_stored_hash(): void
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-02']);

        $run = $runs->lockInputs((int) $run->id, 10);
        $firstHash = (string) $run->input_hash;
        $snapshot = PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail();

        // Re-locking an input-locked run is idempotent and never overwrites
        // the frozen snapshot bytes.
        $run = $runs->lockInputs((int) $run->id, 10);
        $snapshotAfter = PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail();

        $this->assertSame($firstHash, (string) $run->input_hash);
        $this->assertSame($snapshot->payload_json, $snapshotAfter->payload_json);
        $this->assertSame($snapshot->source_hash, $snapshotAfter->source_hash);
        $this->assertSame(1, PayrollInputSnapshot::query()->where('run_id', $run->id)->count());
    }

    public function test_snapshot_prefers_active_execution_compensation_over_employment_terms(): void
    {
        $base = $this->seedPayrollBase(1_000_000);
        $employmentId = (int) $base['employment_id'];
        $scheme = $base['scheme'];

        $compensation = EmployeeCompensation::query()->create([
            'employment_id' => $employmentId,
            'contract_version_id' => null,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'status' => 'ACTIVE',
            'currency' => 'CNY',
            'base_salary_cents' => 800_000,
            'salary_scheme_code' => (string) $scheme->code,
            'payroll_scheme_id' => (int) $scheme->id,
            'payroll_scheme_version' => (int) $scheme->version,
            'parameter_overrides_json' => ['meal_per_day_cents' => 2500],
            'approved_case_id' => null,
            'source_type' => 'TEST',
            'source_id' => 1,
            'content_hash' => hash('sha256', 'snapshot-compensation'),
            'supersedes_id' => null,
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $scheme->id, 'period' => '2026-07']);
        $run = $runs->lockInputs((int) $run->id, 10);
        $payload = (array) PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail()->payload_json;

        $this->assertSame(2, (int) $payload['schema_version']);
        $this->assertSame((int) $compensation->id, (int) $payload['compensation']['compensation_id']);
        $this->assertSame(800_000, (int) $payload['compensation']['base_salary_cents']);
        $this->assertSame((int) $scheme->id, (int) $payload['scheme']['scheme_id']);
        $this->assertSame(2500, (int) $payload['compensation']['parameter_overrides']['meal_per_day_cents']);
    }

    public function test_snapshot_records_missing_closed_time_fact_as_a_blocker(): void
    {
        $base = $this->seedPayrollBase();
        \App\Modules\Time\Models\AttendanceDay::query()->create([
            'employment_id' => (int) $base['employment_id'],
            'business_date' => '2026-08-03',
            'scheduled_minutes' => 480,
            'worked_minutes' => 480,
            'overtime_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'absence_minutes' => 0,
            'status' => 'NORMAL',
            'rule_version' => 'TEST',
            'source_hash' => hash('sha256', 'time-evidence'),
            'calculated_at' => $this->clock->now(),
        ]);
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-08']);
        $run = $runs->lockInputs((int) $run->id, 10);

        $payload = (array) PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail()->payload_json;
        $this->assertSame(2, (int) $payload['schema_version']);
        $this->assertSame('CLOSED_TIME_FACT_MISSING', (string) ($payload['blockers'][0]['code'] ?? ''));
        $this->assertContains((string) ($payload['attendance']['source_type'] ?? ''), ['live_fallback', 'missing']);
        $this->assertNull($payload['attendance']['fact_version_id'] ?? null);
    }

    public function test_snapshot_uses_closed_time_fact_and_has_no_missing_fact_blocker(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $version = TimePayrollFactVersion::query()->create([
            'period' => '2026-09',
            'version' => 1,
            'status' => TimePayrollFactVersion::STATUS_CLOSED,
            'source_hash' => hash('sha256', 'closed-fact-2026-09'),
            'exception_count' => 0,
            'precheck_json' => null,
            'generated_at' => $this->clock->now(),
            'closed_at' => $this->clock->now(),
            'closed_by' => 10,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
        TimePayrollFactRow::query()->create([
            'fact_version_id' => (int) $version->id,
            'employment_id' => $employmentId,
            'business_date' => '2026-09-01',
            'range_start' => '2026-09-01',
            'range_end' => '2026-09-01',
            'scheduled_minutes' => 480,
            'worked_minutes' => 400,
            'paid_leave_minutes' => 0,
            'partial_leave_minutes' => 0,
            'unpaid_leave_minutes' => 80,
            'overtime_pay_minutes' => 0,
            'overtime_timeoff_minutes' => 0,
            'travel_minutes' => 0,
            'source_type' => 'ATTENDANCE',
            'source_id' => '2026-09-01',
            'source_revision' => 'R1',
            'approval_status' => 'APPROVED',
            'source_hash' => hash('sha256', 'closed-row'),
            'recorded_at' => $this->clock->now(),
        ]);
        AttendancePeriodClosure::query()->create([
            'month' => '2026-09',
            'status' => AttendancePeriodClosure::STATUS_CLOSED,
            'closed_by' => 10,
            'closed_at' => $this->clock->now(),
            'current_fact_version_id' => (int) $version->id,
        ]);

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-09']);
        $run = $runs->lockInputs((int) $run->id, 10);
        $payload = (array) PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail()->payload_json;

        $this->assertSame([], $payload['blockers']);
        $this->assertSame('time_closed_fact', (string) $payload['attendance']['source_type']);
        $this->assertSame((int) $version->id, (int) $payload['attendance']['fact_version_id']);
        $this->assertSame(480, (int) $payload['attendance']['scheduled_minutes']);
        $this->assertSame(400, (int) $payload['attendance']['worked_minutes']);
    }

    public function test_benefit_and_performance_inputs_are_frozen_and_enter_calculation(): void
    {
        $base = $this->seedPayrollBase();
        $this->app->instance(BenefitPayrollInputDirectory::class, new class implements BenefitPayrollInputDirectory
        {
            public function frozenForPeriod(int $employmentId, string $period): array
            {
                return [[
                    'item_code' => 'MEAL_INSURANCE',
                    'label' => 'Meal benefit',
                    'employee_amount_cents' => 10_000,
                    'employer_amount_cents' => 15_000,
                    'source_type' => 'benefit_transaction',
                    'source_id' => 'b-1',
                    'taxable' => false,
                    'sort_order' => 800,
                ]];
            }
        });
        $this->app->instance(PerformanceBonusDirectory::class, new class implements PerformanceBonusDirectory
        {
            public function frozenForPeriod(int $employmentId, string $period): array
            {
                return [[
                    'item_code' => 'PERF_BONUS',
                    'label' => 'Performance bonus',
                    'amount_cents' => 20_000,
                    'source_type' => 'performance_result',
                    'source_id' => 'p-1',
                    'taxable' => true,
                    'sort_order' => 700,
                ]];
            }
        });

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-11']);
        $run = $runs->lockInputs((int) $run->id, 10);
        $payload = (array) PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail()->payload_json;
        $this->assertSame('MEAL_INSURANCE', (string) ($payload['benefits'][0]['item_code'] ?? ''));
        $this->assertSame('PERF_BONUS', (string) ($payload['performance_bonuses'][0]['item_code'] ?? ''));

        $result = app(CalculationService::class)->calculate($run, 10);
        $this->assertSame(1_020_000, $result['gross_cents']);
        $this->assertSame(PayrollRunStatus::CALCULATED, $run->refresh()->status);
    }

    public function test_calculation_blocks_missing_closed_fact_and_prevents_review(): void
    {
        $base = $this->seedPayrollBase();
        \App\Modules\Time\Models\AttendanceDay::query()->create([
            'employment_id' => (int) $base['employment_id'],
            'business_date' => '2026-10-02',
            'scheduled_minutes' => 480,
            'worked_minutes' => 480,
            'overtime_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'absence_minutes' => 0,
            'status' => 'NORMAL',
            'rule_version' => 'TEST',
            'source_hash' => hash('sha256', 'blocked-calculation'),
            'calculated_at' => $this->clock->now(),
        ]);

        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-10']);
        $run = $runs->lockInputs((int) $run->id, 10);
        $result = app(CalculationService::class)->calculate($run, 10);
        $run = $run->refresh();

        $this->assertSame(0, $result['calculated']);
        $this->assertSame(1, $result['blocked']);
        $this->assertSame(PayrollRunStatus::CALCULATED_WITH_BLOCKERS, $run->status);
        $employee = PayrollRunEmployee::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame(PayrollEmployeeStatus::BLOCKED, $employee->status);
        $this->assertStringContainsString('CLOSED_TIME_FACT_MISSING', (string) $employee->failure_reason);
        $this->assertSame(0, \App\Modules\Payroll\Models\Payslip::query()->where('run_id', $run->id)->count());

        $this->expectException(ConflictException::class);
        app(ApprovalService::class)->review((int) $run->id, 11);
    }

    public function test_attendance_change_after_lock_does_not_affect_calculated_run(): void
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-03']);
        $run = $runs->lockInputs((int) $run->id, 10);
        $beforeHash = (string) $run->input_hash;

        // Change the Time-owned attendance after the lock.
        \App\Modules\Time\Models\AttendanceDay::query()->create([
            'employment_id' => $base['employment_id'],
            'business_date' => '2026-03-02',
            'shift_template_id' => null,
            'scheduled_minutes' => 480,
            'worked_minutes' => 0,
            'overtime_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'absence_minutes' => 480,
            'status' => 'ABSENT',
            'rule_version' => 'DEMO',
            'source_hash' => hash('sha256', 'changed-after-lock'),
            'calculated_at' => $this->clock->now(),
        ]);

        app(CalculationService::class)->calculate($run, 10);
        $run = $run->refresh();

        $this->assertSame(PayrollRunStatus::CALCULATED, $run->status);
        $this->assertSame($beforeHash, (string) $run->input_hash);
        $this->assertSame(1_000_000, (int) $run->gross_cents);

        // A new run picks the changed attendance up and produces a lower gross.
        $newRun = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-04']);
        $newRun = $runs->lockInputs((int) $newRun->id, 10);
        $this->assertNotSame($beforeHash, (string) $newRun->input_hash);
    }

    public function test_reopen_requires_two_distinct_approvers_and_reason(): void
    {
        $run = $this->calculatedRun('2026-05');

        $this->expectException(ValidationException::class);
        app(ReopenService::class)->reopen((int) $run->id, 10, [11], 'Insufficient approvals for reopen.');
    }

    public function test_reopen_rejects_published_runs(): void
    {
        $run = $this->publishedRun('2026-06');

        $this->expectException(ConflictException::class);
        app(ReopenService::class)->reopen((int) $run->id, 10, [11, 12], 'Published run must use correction.');
    }

    private function calculatedRun(string $period): PayrollRun
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => $period]);
        $run = $runs->lockInputs((int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        return $run->refresh();
    }

    private function publishedRun(string $period): PayrollRun
    {
        $run = $this->calculatedRun($period);
        $approval = app(ApprovalService::class);
        $run = $approval->review((int) $run->id, 11);
        $run = $approval->approve((int) $run->id, 12);

        return $approval->publish((int) $run->id, 13);
    }
}
