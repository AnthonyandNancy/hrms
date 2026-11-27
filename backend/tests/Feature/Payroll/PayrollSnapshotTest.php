<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\ReopenService;
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
