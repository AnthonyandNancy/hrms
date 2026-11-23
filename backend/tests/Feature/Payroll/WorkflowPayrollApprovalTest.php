<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use Tests\Support\PayrollTestCase;

/**
 * Payroll approval must be backed by a Workflow Case/Decision/Effect. The
 * Workflow template and effect are code-owned and idempotent; the effect
 * transitions REVIEWED -> APPROVED exactly once.
 */
final class WorkflowPayrollApprovalTest extends PayrollTestCase
{
    public function test_approval_effect_is_idempotent_and_advances_the_run_once(): void
    {
        $run = $this->reviewedRun();
        $effect = app(\App\Modules\Payroll\Contracts\PayrollApprovalEffectPort::class);

        $effect->applyApproval($this->tenantId, (int) $run->id, 12);
        $effect->applyApproval($this->tenantId, (int) $run->id, 12);

        $run->refresh();
        $this->assertSame(PayrollRunStatus::APPROVED, $run->status);
        $this->assertSame(12, (int) $run->approved_by);
    }

    public function test_approval_effect_fails_closed_for_unreviewed_run(): void
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun($this->tenantId, 10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-02']);

        $this->expectException(\App\Shared\Exceptions\ConflictException::class);
        app(\App\Modules\Payroll\Contracts\PayrollApprovalEffectPort::class)->applyApproval($this->tenantId, (int) $run->id, 12);
    }

    private function reviewedRun(): PayrollRun
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun($this->tenantId, 10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-01']);
        $run = $runs->lockInputs($this->tenantId, (int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        return app(ApprovalService::class)->review($this->tenantId, (int) $run->id, 11);
    }
}
