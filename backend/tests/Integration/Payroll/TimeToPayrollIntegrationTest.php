<?php

declare(strict_types=1);

namespace Tests\Integration\Payroll;

use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Services\PayrollInputDirectoryService;
use Tests\Support\PayrollTestCase;

/**
 * Time -> Payroll integration through the Time\Contracts\PayrollInputDirectory
 * contract. Minutes are integer and the payroll calculation never reads Time
 * models directly.
 */
final class TimeToPayrollIntegrationTest extends PayrollTestCase
{
    public function test_unpaid_leave_reduces_gross_and_paid_leave_does_not(): void
    {
        $base = $this->seedPayrollBase(1_000_000);

        $annual = $this->leaveType('ANNUAL', 'Annual leave');
        $unpaid = $this->leaveType('UNPAID', 'Unpaid leave');

        // 480 scheduled minutes; 240 worked, 240 annual paid, zero unpaid.
        $this->attendance($base['employment_id'], '2026-01-05', 480, 240, 0);
        $this->leaveEntry($base['employment_id'], $annual->id, '2026-01-05', -240);

        $paidOnlyGross = $this->calculateGross($base, '2026-01');
        $this->assertSame(1_000_000, $paidOnlyGross);

        // The next month is a full day of unpaid leave.
        $this->attendance($base['employment_id'], '2026-02-05', 480, 0, 0);
        $this->leaveEntry($base['employment_id'], $unpaid->id, '2026-02-05', -480);

        $unpaidGross = $this->calculateGross($base, '2026-02');
        $this->assertLessThan($paidOnlyGross, $unpaidGross);
        $this->assertGreaterThan(0, $unpaidGross);
    }

    public function test_overtime_minutes_add_a_traceable_earning(): void
    {
        $base = $this->seedPayrollBase(1_000_000);
        $this->attendance($base['employment_id'], '2026-03-05', 480, 480, 120);

        $overtimeGross = $this->calculateGross($base, '2026-03');
        $this->assertGreaterThan(1_000_000, $overtimeGross);
    }

    public function test_directory_reports_recorded_attendance_only_when_evidence_exists(): void
    {
        $base = $this->seedPayrollBase(1_000_000);
        $directory = app(PayrollInputDirectoryService::class);

        $this->assertFalse($directory->hasRecordedAttendance((int) $base['employment_id'], '2026-05'));

        $this->attendance($base['employment_id'], '2026-05-05', 480, 480, 0);

        $this->assertTrue($directory->hasRecordedAttendance((int) $base['employment_id'], '2026-05'));
    }

    private function leaveType(string $code, string $name): LeaveType
    {
        return LeaveType::query()->create([
            'code' => $code,
            'name' => $name,
            'is_statutory' => false,
            'is_annual' => $code === 'ANNUAL',
            'unit' => 'DAY',
            'annual_entitlement_days' => '0.00',
            'requires_attachment' => false,
            'allow_negative' => false,
            'expiry_rule_json' => null,
            'legal_basis' => 'DEMO',
            'version' => 1,
        ]);
    }

    private function attendance(int $employmentId, string $date, int $scheduled, int $worked, int $overtime): void
    {
        AttendanceDay::query()->create([
            'employment_id' => $employmentId,
            'business_date' => $date,
            'shift_template_id' => null,
            'scheduled_minutes' => $scheduled,
            'worked_minutes' => $worked,
            'overtime_minutes' => $overtime,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'absence_minutes' => max(0, $scheduled - $worked),
            'status' => 'CALCULATED',
            'rule_version' => 'DEMO',
            'source_hash' => hash('sha256', $date.'|'.$scheduled.'|'.$worked.'|'.$overtime),
            'calculated_at' => $this->clock->now(),
        ]);
    }

    private function leaveEntry(int $employmentId, int $leaveTypeId, string $effectiveOn, int $minutes): void
    {
        LeaveLedgerEntry::query()->create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'account' => 'CONSUME',
            'quantity_minutes' => $minutes,
            'business_date' => $effectiveOn,
            'effective_on' => $effectiveOn,
            'source_type' => 'TEST',
            'source_id' => 'LEAVE-'.bin2hex(random_bytes(4)),
            'case_id' => null,
            'reason' => 'Synthetic integration leave entry',
            'entry_hash' => hash('sha256', $effectiveOn.'|'.$minutes.'|'.$leaveTypeId),
            'recorded_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * @param  array{scheme: \App\Modules\Payroll\Models\PayrollScheme, employment_id: int}  $base
     */
    private function calculateGross(array $base, string $period): int
    {
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => $period]);
        $run = $runs->lockInputs((int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        return (int) $run->refresh()->gross_cents;
    }
}
