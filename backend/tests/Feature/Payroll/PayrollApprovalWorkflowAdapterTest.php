<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Workflow\Contracts\PayrollApprovalWorkflow;
use Tests\Support\PayrollTestCase;

/**
 * Exercises the Workflow case/decision path itself, not only the payroll-side
 * effect port. The case must end APPROVED with the effect APPLIED and the run
 * must transition to APPROVED exactly once.
 */
final class PayrollApprovalWorkflowAdapterTest extends PayrollTestCase
{
    public function test_workflow_case_decision_and_effect_approve_the_run(): void
    {
        $run = $this->reviewedRun();

        $port = app(PayrollApprovalWorkflow::class);
        $caseId = $port->open(10, (int) $run->id, 12, '2026-01-31');

        $result = $port->approve($caseId, 12, 'Approved in workflow test.', '2026-01-31');

        $this->assertSame('APPROVED', $result['application_status']);
        $this->assertSame('APPLIED', $result['effect_status']);
        $this->assertSame('APPROVED', $run->refresh()->status->value);
    }

    private function reviewedRun(): \App\Modules\Payroll\Models\PayrollRun
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-01']);
        $run = $runs->lockInputs((int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        return app(ApprovalService::class)->review((int) $run->id, 11);
    }
}
