<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Adapters;

use App\Modules\Engagement\Contracts\NoticeApprovalPort;
use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\CaseDecisionPort;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Shared\Exceptions\NotFoundException;

/**
 * Engagement -> Workflow adapter. It imports only Workflow Contracts, never
 * models or internal services. Approval status is read from the Case snapshot,
 * never inferred from a notice status field.
 */
final class NoticeApprovalWorkflowAdapter implements NoticeApprovalPort
{
    private const CASE_TYPE = 'NOTICE_APPROVAL';

    public function __construct(
        private readonly CaseCommandPort $cases,
        private readonly CaseDecisionPort $decisions,
        private readonly WorkflowCaseGateway $gateway,
    ) {}

    public function ensureTemplate(): void
    {
        $this->gateway->ensureTemplate('notice.approval', 'Notice approval', [
            'nodes' => [
                ['key' => 'start', 'name' => 'Start', 'type' => 'START', 'sequence' => 1],
                ['key' => 'hr_review', 'name' => 'Notice review', 'type' => 'APPROVAL', 'sequence' => 2, 'assignee_rule' => ['type' => 'ROLE', 'role_code' => 'HR_ADMIN'], 'required_count' => 1, 'sla_hours' => 24],
                ['key' => 'end', 'name' => 'End', 'type' => 'END', 'sequence' => 3],
            ],
            'effects' => [],
        ]);
    }

    public function open(
        WorkflowPrincipal $principal,
        int $noticeId,
        string $title,
        int $audienceCount,
        array $recipientUserIds,
        string $businessDate,
    ): array {
        $this->ensureTemplate();

        $case = $this->cases->open(
            principal: $principal,
            type: self::CASE_TYPE,
            subjectType: 'notice',
            subjectId: $noticeId,
            title: $title,
            payload: [
                'notice_id' => $noticeId,
                'title' => $title,
                'audience_count' => $audienceCount,
                'recipient_user_ids' => $recipientUserIds,
            ],
            businessDate: $businessDate,
            submit: true,
        );

        return [
            'case_id' => (int) $case['id'],
            'case_no' => (string) $case['case_no'],
            'status' => (string) $case['status'],
        ];
    }

    public function decide(
        WorkflowPrincipal $principal,
        int $noticeId,
        int $caseId,
        string $action,
        string $comment,
        string $businessDate,
    ): array {
        // Validate ownership before recording evidence. A case for a different
        // notice must fail closed with the same not-found response as unknown.
        $case = $this->cases->find($caseId);

        if ($case === null
            || $case['subject_type'] !== 'notice'
            || (int) ($case['subject_id'] ?? 0) !== $noticeId) {
            throw new NotFoundException;
        }

        $decision = $this->decisions->decide(
            userId: $principal->userId,
            caseId: $caseId,
            action: $action,
            comment: $comment,
            businessDate: $businessDate,
        );

        return [
            'case_id' => $decision['case_id'],
            'case_status' => $decision['case_status'],
            'effect_status' => $decision['effect_status'],
        ];
    }

    public function find(int $caseId): ?array
    {
        $case = $this->cases->find($caseId);

        if ($case === null) {
            return null;
        }

        return ['status' => $case['status'], 'payload' => $case['payload']];
    }
}
