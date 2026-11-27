<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\Exceptions\ValidationException;
use Tests\Support\PayrollTestCase;

final class MarkPaidEvidenceTest extends PayrollTestCase
{
    public function test_mark_paid_without_reference_or_manual_reason_is_rejected(): void
    {
        $run = $this->publishedRun();

        $this->expectException(ValidationException::class);
        app(ApprovalService::class)->markPaid((int) $run->id, 13);
    }

    public function test_mark_paid_accepts_manual_confirmation_reason(): void
    {
        $run = $this->publishedRun();

        $paid = app(ApprovalService::class)->markPaid(
            (int) $run->id,
            13,
            '',
            'Manual bank confirmation checked by finance.',
        );

        $this->assertSame('PAID', $paid->status->value);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'payroll.run.paid',
            'aggregate_id' => (string) $run->id,
        ]);
    }

    private function publishedRun(): \App\Modules\Payroll\Models\PayrollRun
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-01']);
        $run = $runs->lockInputs((int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);
        $approval = app(ApprovalService::class);
        $approval->review((int) $run->id, 11);
        $approval->approve((int) $run->id, 12);

        return $approval->publish((int) $run->id, 13);
    }
}
