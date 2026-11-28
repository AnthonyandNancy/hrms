<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Workflow\Contracts\PayrollApprovalWorkflow;
use App\Modules\Workflow\Models\WorkflowTemplate;
use App\Modules\Workflow\Services\TemplateService;
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

    public function test_legacy_generic_payroll_template_is_replaced_by_deterministic_version(): void
    {
        app(TemplateService::class)->create(0, [
            'code' => 'payroll.approval',
            'name' => 'Legacy payroll approval',
            'version' => 1,
            'status' => 'PUBLISHED',
            'definition' => [
                'nodes' => [
                    ['key' => 'start', 'sequence' => 1, 'type' => 'START'],
                    ['key' => 'approval', 'sequence' => 2, 'type' => 'APPROVAL', 'assignee_rule' => ['type' => 'MANAGER_OF_SUBJECT'], 'required_count' => 1],
                    ['key' => 'end', 'sequence' => 3, 'type' => 'END'],
                ],
                'effects' => [['type' => 'workflow.noop', 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id']],
            ],
        ]);

        $run = $this->reviewedRun();
        $port = app(PayrollApprovalWorkflow::class);
        $caseId = $port->open(10, (int) $run->id, 12, '2026-01-31');
        $result = $port->approve($caseId, 12, 'Approved after legacy repair.', '2026-01-31');

        $this->assertSame('APPROVED', $result['application_status']);
        $this->assertSame('APPLIED', $result['effect_status']);
        $this->assertSame('APPROVED', $run->refresh()->status->value);

        $latest = WorkflowTemplate::query()->where('code', 'payroll.approval')->orderByDesc('version')->firstOrFail();
        $this->assertSame(2, (int) $latest->version);
        $this->assertSame('workflow.payroll.publish', (string) ($latest->definition_json['effects'][0]['type'] ?? ''));
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
