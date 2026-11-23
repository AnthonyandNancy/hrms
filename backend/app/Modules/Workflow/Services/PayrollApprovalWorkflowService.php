<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\PayrollApprovalWorkflow;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Enums\CaseStatus;
use App\Modules\Workflow\Enums\DecisionAction;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\WorkflowTemplate;
use App\Shared\Exceptions\ConflictException;

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
    ) {}

    public function open(int $tenantId, int $requesterUserId, int $runId, string $businessDate): int
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
            submit: true,
        );

        return (int) $case->id;
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

        throw new ConflictException('The payroll.approval workflow template is not published for this tenant.', [
            'reason' => 'PAYROLL_APPROVAL_TEMPLATE_MISSING',
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
