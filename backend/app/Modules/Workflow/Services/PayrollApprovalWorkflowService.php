<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\PayrollApprovalWorkflow;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Enums\CaseStatus;
use App\Modules\Workflow\Enums\DecisionAction;
use App\Modules\Workflow\Models\CaseMember;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\WorkflowTemplate;

/**
 * Deterministic payroll.approval workflow adapter. A dedicated template is
 * created for the tenant so a real Case/Decision/Effect is always the source
 * of approval evidence, not a bare status column.
 */
final class PayrollApprovalWorkflowService implements PayrollApprovalWorkflow
{
    public const TEMPLATE_CODE = 'payroll.approval';

    public function __construct(
        private readonly CaseService $cases,
        private readonly DecisionService $decisions,
        private readonly CaseQueryService $queries,
        private readonly TemplateService $templates,
    ) {}

    public function open(int $tenantId, int $requesterUserId, int $runId, int $assigneeUserId, string $businessDate): int
    {
        $template = $this->template($tenantId);
        $principal = $this->principal($tenantId, $requesterUserId);

        $case = $this->cases->create(
            principal: $principal,
            type: 'payroll.approval',
            subjectType: 'payroll_run',
            subjectId: $runId,
            title: 'Payroll run approval',
            payload: ['payroll_run_id' => $runId],
            templateId: (int) $template->id,
            businessDate: $businessDate,
            submit: false,
        );

        // The approving actor is a case member; this keeps one reusable
        // template per tenant while still assigning the task to the right user.
        CaseMember::query()->firstOrCreate(
            ['case_id' => $case->id, 'user_id' => $assigneeUserId, 'role' => 'APPROVER'],
            ['tenant_id' => $tenantId, 'created_at' => now()],
        );

        $submitted = $this->cases->submit($principal, $case);

        return (int) $submitted->id;
    }

    public function approve(int $tenantId, int $caseId, int $actorUserId, string $comment, string $businessDate): array
    {
        $principal = $this->principal($tenantId, $actorUserId);

        $case = $this->decisions->decide(
            principal: $principal,
            caseId: $caseId,
            action: DecisionAction::APPROVE,
            comment: $comment,
            targetUserId: null,
            reason: null,
        );

        $data = $this->queries->detail($principal, (int) $case->id);

        return [
            'case_id' => (int) $case->id,
            'application_status' => (string) $data['application_status'],
            'effect_status' => (string) $data['effect_status'],
        ];
    }

    public function status(int $tenantId, int $caseId): array
    {
        $case = CaseModel::query()->where('tenant_id', $tenantId)->whereKey($caseId)->first();

        if ($case === null) {
            return ['application_status' => null, 'effect_status' => null];
        }

        $status = $case->status instanceof CaseStatus ? $case->status->value : (string) $case->status;
        $effect = $case->effect_status;

        return [
            'application_status' => $status,
            'effect_status' => $effect instanceof \BackedEnum ? (string) $effect->value : (string) $effect,
        ];
    }

    private function template(int $tenantId): WorkflowTemplate
    {
        $template = WorkflowTemplate::query()
            ->where('tenant_id', $tenantId)
            ->where('code', self::TEMPLATE_CODE)
            ->where('status', 'PUBLISHED')
            ->orderByDesc('version')
            ->first();

        if ($template !== null) {
            return $template;
        }

        // Payroll approval is a narrow built-in workflow. It is created on
        // first use; each case names its approver as an APPROVER case member.
        // Review and SOD are enforced by the Payroll domain before a case is
        // opened; the case is the durable Decision/Effect evidence.
        return $this->templates->create($tenantId, 1, [
            'code' => self::TEMPLATE_CODE,
            'name' => 'Payroll run approval',
            'version' => 1,
            'status' => 'PUBLISHED',
            'definition' => [
                'nodes' => [
                    ['key' => 'start', 'sequence' => 1, 'type' => 'START'],
                    [
                        'key' => 'approve',
                        'sequence' => 2,
                        'type' => 'APPROVAL',
                        'assignee_rule' => ['type' => 'CASE_MEMBER', 'role' => 'APPROVER'],
                        'required_count' => 1,
                    ],
                    ['key' => 'end', 'sequence' => 3, 'type' => 'END'],
                ],
                'effects' => [[
                    'type' => 'workflow.payroll.publish',
                    'aggregate_type' => 'payroll_run',
                    'aggregate_id_field' => 'subject_id',
                    'execution' => 'LOCAL',
                ]],
            ],
        ]);
    }

    private function principal(int $tenantId, int $userId): WorkflowPrincipal
    {
        return new WorkflowPrincipal(
            tenantId: $tenantId,
            userId: $userId,
            employmentId: null,
            authVersion: 1,
            isPrivileged: false,
            roles: [],
            capabilities: ['workflow.case.read', 'workflow.case.create', 'workflow.case.decide', 'workflow.case.admin'],
        );
    }
}
