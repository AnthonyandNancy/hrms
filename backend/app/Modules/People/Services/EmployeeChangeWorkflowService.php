<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\EmployeeChangeApprovalPort;
use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use App\Shared\Exceptions\ConflictException;

/**
 * People adapter over Workflow's narrow case contracts. It intentionally
 * imports no Workflow model, enum or service and therefore cannot write a Case
 * table directly.
 *
 * The template is code-owned by People and ensured through the Workflow
 * gateway, so submission never depends on an operator manually publishing a
 * template first. The local effect type is registered by the module provider
 * integration (see docs/implementation/M04/integration.json).
 */
final class EmployeeChangeWorkflowService implements EmployeeChangeApprovalPort
{
    public const CASE_TYPE = 'EMPLOYEE_PROFILE_CHANGE';

    public const TEMPLATE_CODE = 'employee.profile.change';

    public function __construct(
        private readonly WorkflowCaseGateway $cases,
        private readonly CaseCommandPort $caseQueries,
        private readonly WorkflowPrincipalResolver $principals,
    ) {}

    public function open(
        int $actorUserId,
        int $employmentId,
        int $changeRequestId,
        ?string $resource,
        ?int $recordId,
        array $changes,
        string $reason,
        string $businessDate,
    ): array {
        $principal = $this->principalFor($actorUserId);

        $this->cases->ensureTemplate(self::TEMPLATE_CODE, 'Employee profile change', [
            'nodes' => [
                ['key' => 'start', 'name' => 'Start', 'type' => 'START', 'sequence' => 1],
                [
                    'key' => 'hr_review',
                    'name' => 'HR review',
                    'type' => 'APPROVAL',
                    'sequence' => 2,
                    'assignee_rule' => ['type' => 'ROLE', 'role_code' => 'HR_ADMIN'],
                    'required_count' => 1,
                    'sla_hours' => 48,
                ],
                ['key' => 'end', 'name' => 'End', 'type' => 'END', 'sequence' => 3],
            ],
            'effects' => [[
                'type' => EmployeeProfileChangeEffectHandler::EFFECT_TYPE,
                'execution' => 'LOCAL',
                'aggregate_type' => 'employee_change_request',
                'aggregate_id_field' => 'case_id',
                'outbox_event_type' => 'people.employee.change.applied.v1',
            ]],
        ]);

        $case = $this->cases->createCase(
            requesterUserId: $principal->userId,
            type: self::CASE_TYPE,
            subjectType: 'employment',
            subjectId: $employmentId,
            title: '员工资料变更',
            payload: [
                'change_request_id' => $changeRequestId,
                'employment_id' => $employmentId,
                'resource' => $resource,
                'record_id' => $recordId,
                'changes' => $changes,
                'reason' => $reason,
            ],
            templateCode: self::TEMPLATE_CODE,
            businessDate: $businessDate,
            submit: true,
        );

        if ($case->id <= 0 || trim($case->caseNo) === '') {
            throw new ConflictException(
                'The approval case did not return a stable case reference.',
                ['reason' => 'WORKFLOW_CASE_REFERENCE_INVALID'],
            );
        }

        return [
            'case_id' => $case->id,
            'case_no' => $case->caseNo,
            'status' => $case->applicationStatus,
        ];
    }

    public function find(int $caseId): ?array
    {
        $case = $this->caseQueries->find($caseId);

        if ($case === null) {
            return null;
        }

        return [
            'status' => (string) ($case['status'] ?? ''),
            'case_no' => (string) ($case['case_no'] ?? ''),
            'subject_type' => (string) ($case['subject_type'] ?? ''),
            'subject_id' => $case['subject_id'] !== null ? (int) $case['subject_id'] : null,
        ];
    }

    private function principalFor(int $actorUserId): WorkflowPrincipal
    {
        $principal = $this->principals->resolve();

        if ($principal === null || $principal->userId !== $actorUserId) {
            throw new ConflictException(
                'The workflow principal does not match the change requester.',
                ['reason' => 'WORKFLOW_PRINCIPAL_MISMATCH'],
            );
        }

        return $principal;
    }
}
