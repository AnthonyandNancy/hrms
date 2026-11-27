<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Contracts\NoticeApprovalPort;
use App\Modules\Engagement\Models\Notice;
use App\Modules\Engagement\Models\NoticeAcknowledgement;
use App\Modules\Engagement\Models\NoticeTemplate;
use App\Modules\IAM\Contracts\Principal;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Notice governance: approval lifecycle, audience preview, read evidence,
 * versioned templates and archive/restore.
 *
 * Approval is never a notice status write: decisions are recorded by Workflow
 * through NoticeApprovalPort, and publication re-reads the Case status.
 */
final class NoticeGovernanceService
{
    private const EXPORT_FIELDS = [
        'user_id',
        'acknowledged_at',
        'employment_id',
        'employee_no',
        'display_name',
        'notice_id',
    ];

    public function __construct(
        private readonly NoticeAudienceService $audience,
        private readonly NoticeAccessPolicy $policy,
        private readonly NotificationService $notifications,
        private readonly NoticeApprovalPort $approval,
        private readonly AuditRecorder $audit,
        private readonly SensitiveAccessAuditor $sensitiveAuditor,
        private readonly CompanyBusinessDate $businessDates,
        private readonly Clock $clock,
    ) {}

    public function approvalTemplate(): void
    {
        $this->approval->ensureTemplate();
    }

    public function businessDate(): string
    {
        return $this->businessDates->today()->value();
    }

    /**
     * Submit a draft notice for approval. The notice must already have been
     * created with approval_required=true.
     */
    /**
     * @return array<string, mixed>
     */
    public function submit(Principal $principal, int $noticeId, string $businessDate): array
    {
        $today = $this->businessDates->resolve($businessDate !== '' ? $businessDate : null)->value();
        $notice = $this->manageable($principal, $noticeId, $today);

        if ($notice->status !== Notice::STATUS_DRAFT) {
            throw new ConflictException('Only a draft notice can be submitted for approval.', [
                'reason' => 'NOTICE_NOT_DRAFT',
                'status' => (string) $notice->status,
            ]);
        }

        $workflowPrincipal = $this->workflowPrincipal($principal);
        $audience = (array) $notice->audience_json;
        $recipients = $this->audience->recipientsForSnapshot($audience, $today);
        $case = $this->approval->open(
            principal: $workflowPrincipal,
            noticeId: (int) $notice->id,
            title: (string) $notice->title,
            audienceCount: count($recipients),
            recipientUserIds: $recipients,
            businessDate: $today,
        );

        $notice->forceFill([
            'status' => Notice::STATUS_PENDING_APPROVAL,
            'approval_case_id' => $case['case_id'],
            'audience_count' => count($recipients),
            'publish_at' => $this->clock->now(),
        ])->save();

        $this->audit->record(
            actorUserId: $principal->userId(),
            action: 'engagement.notice.submitted',
            aggregateType: 'notice',
            aggregateId: (int) $notice->id,
            after: ['case_id' => $case['case_id'], 'case_no' => $case['case_no']],
        );

        return $this->noticesPayload($principal, $notice->refresh(), $today);
    }

    /**
     * Apply an APPROVE/REJECT decision through Workflow, then mirror the Case
     * state onto the notice. A notice can never be approved by this method
     * without the Case decision actually succeeding.
     */
    /**
     * @return array<string, mixed>
     */
    public function decide(Principal $principal, int $noticeId, string $action, string $comment, string $businessDate): array
    {
        $today = $this->businessDates->resolve($businessDate !== '' ? $businessDate : null)->value();
        $notice = $this->noticeOrFail($noticeId);

        if ($notice->status !== Notice::STATUS_PENDING_APPROVAL || $notice->approval_case_id === null) {
            throw new ConflictException('This notice has no approval case awaiting a decision.', [
                'reason' => 'NOTICE_NOT_PENDING_APPROVAL',
                'status' => (string) $notice->status,
            ]);
        }

        $decision = $this->approval->decide(
            principal: $this->workflowPrincipal($principal),
            noticeId: (int) $notice->id,
            caseId: (int) $notice->approval_case_id,
            action: $action,
            comment: $comment,
            businessDate: $today,
        );

        $caseStatus = strtoupper($decision['case_status']);
        $status = match ($caseStatus) {
            'APPROVED' => Notice::STATUS_APPROVED,
            'REJECTED' => Notice::STATUS_REJECTED,
            default => Notice::STATUS_PENDING_APPROVAL,
        };

        $notice->forceFill([
            'status' => $status,
            'approved_at' => $caseStatus === 'APPROVED' ? $this->clock->now() : null,
            'approved_by' => $caseStatus === 'APPROVED' ? $principal->userId() : null,
        ])->save();

        $this->audit->record(
            actorUserId: $principal->userId(),
            action: 'engagement.notice.decision',
            aggregateType: 'notice',
            aggregateId: (int) $notice->id,
            after: [
                'case_id' => $decision['case_id'],
                'case_status' => $caseStatus,
                'notice_status' => $status,
                'action' => strtoupper($action),
            ],
        );

        $payload = $this->noticesPayload($principal, $notice->refresh(), $today);

        return [
            ...$payload,
            'notice_status' => $status,
            'application_status' => $caseStatus,
            'case_id' => $decision['case_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(Principal $principal, int $noticeId, string $businessDate): array
    {
        $today = $this->businessDates->resolve($businessDate !== '' ? $businessDate : null)->value();
        $notice = $this->manageable($principal, $noticeId, $today);

        if ($notice->approval_case_id !== null) {
            $case = $this->approval->find((int) $notice->approval_case_id);

            if (($case['status'] ?? null) !== 'APPROVED') {
                throw new ConflictException('A notice must be approved by its Workflow Case before publication.', [
                    'reason' => 'NOTICE_NOT_APPROVED',
                    'notice_status' => (string) $notice->status,
                    'case_status' => $case['status'] ?? null,
                ]);
            }
        }

        if ($notice->status === Notice::STATUS_REJECTED) {
            throw new ConflictException('A rejected notice cannot be published.', [
                'reason' => 'NOTICE_NOT_APPROVED',
                'notice_status' => (string) $notice->status,
            ]);
        }

        if (! in_array($notice->status, [Notice::STATUS_APPROVED, Notice::STATUS_PUBLISHED], true)) {
            throw new ConflictException('Only an approved notice can be published.', [
                'reason' => 'NOTICE_NOT_APPROVED',
                'notice_status' => (string) $notice->status,
            ]);
        }

        $audience = (array) $notice->audience_json;
        $recipients = $this->audience->recipientsForSnapshot($audience, $today);

        DB::transaction(function () use ($notice, $principal, $recipients): void {
            $notice->forceFill([
                'status' => Notice::STATUS_PUBLISHED,
                'publish_at' => $this->clock->now(),
                'audience_count' => count($recipients),
            ])->save();

            $this->notifications->notify(
                userIds: $recipients,
                type: 'NOTICE_PUBLISHED',
                payload: [
                    'notice_id' => (int) $notice->id,
                    'requires_ack' => (bool) $notice->requires_ack,
                ],
            );

            $this->audit->record(
                actorUserId: $principal->userId(),
                action: 'engagement.notice.published',
                aggregateType: 'notice',
                aggregateId: (int) $notice->id,
                after: ['recipient_count' => count($recipients)],
            );
        });

        return $this->noticesPayload($principal, $notice->refresh(), $today);
    }

    /**
     * Resolve the audience against the live directory at request time.
     *
     * @return array<string, mixed>
     */
    public function audiencePreview(Principal $principal, int $noticeId, string $businessDate): array
    {
        $today = $this->businessDates->resolve($businessDate !== '' ? $businessDate : null)->value();
        $notice = $this->manageable($principal, $noticeId, $today);
        $audience = (array) $notice->audience_json;
        $recipients = $this->audience->recipientsForSnapshot($audience, $today);

        return [
            'notice_id' => (int) $notice->id,
            'audienceType' => (string) ($audience['type'] ?? 'ALL'),
            'audienceLabel' => (string) ($audience['label'] ?? ''),
            'orgUnitIds' => array_values(array_map('intval', (array) ($audience['org_unit_ids'] ?? []))),
            'roleCodes' => array_values(array_map('strval', (array) ($audience['role_codes'] ?? []))),
            'recipientUserIds' => $recipients,
            'recipientCount' => count($recipients),
            'resolvedOn' => $today,
        ];
    }

    /**
     * Read-detail projection. The export variant is whitelisted and audited.
     *
     * @return array{items: list<array<string, mixed>>, total: int, as_of: string}
     */
    public function reads(Principal $principal, int $noticeId, int $page, int $pageSize): array
    {
        $today = $this->businessDates->today()->value();
        $notice = $this->manageable($principal, $noticeId, $today);

        $query = NoticeAcknowledgement::query()
            ->where('notice_id', (int) $notice->id)
            ->orderBy('acknowledged_at')
            ->orderBy('id');

        $total = (int) (clone $query)->count();
        $items = $query->forPage($page, $pageSize)->get()->map(fn (NoticeAcknowledgement $ack): array => [
            'user_id' => (int) $ack->user_id,
            'acknowledged_at' => $ack->acknowledged_at?->toIso8601ZuluString(),
        ])->all();

        return ['items' => $items, 'total' => $total, 'as_of' => $today];
    }

    /**
     * @param  list<string>  $requestedFields
     * @return array{fields: list<string>, rows: list<array<string, mixed>>, row_count: int, as_of: string}
     */
    public function exportReads(Principal $principal, int $noticeId, array $requestedFields): array
    {
        $today = $this->businessDates->today()->value();
        $notice = $this->manageable($principal, $noticeId, $today);

        $fields = $requestedFields === []
            ? ['user_id', 'acknowledged_at']
            : array_values(array_unique($requestedFields));

        $forbidden = array_values(array_diff($fields, self::EXPORT_FIELDS));

        if ($forbidden !== []) {
            throw new ValidationException(
                ['fields' => ['The requested export fields are not in the whitelist.']],
                'The given data was invalid.',
                ['reason' => 'EXPORT_FIELD_NOT_ALLOWED', 'forbidden_fields' => $forbidden],
            );
        }

        $rows = NoticeAcknowledgement::query()
            ->where('notice_id', (int) $notice->id)
            ->orderBy('acknowledged_at')
            ->orderBy('id')
            ->get()
            ->map(static function (NoticeAcknowledgement $ack) use ($fields): array {
                $row = [
                    'user_id' => (int) $ack->user_id,
                    'acknowledged_at' => $ack->acknowledged_at?->toIso8601ZuluString(),
                    'employment_id' => null,
                    'employee_no' => null,
                    'display_name' => null,
                    'notice_id' => (int) $ack->notice_id,
                ];

                return array_intersect_key($row, array_fill_keys($fields, true));
            })
            ->all();

        $this->sensitiveAuditor->record(
            userId: $principal->userId(),
            capabilityCode: 'engagement.notice.export',
            resourceType: 'notice_acknowledgement',
            resourceId: (int) $notice->id,
            fields: $fields,
            context: ['notice_id' => (int) $notice->id, 'export' => true, 'row_count' => count($rows)],
        );

        $this->audit->record(
            actorUserId: $principal->userId(),
            action: 'engagement.notice.reads.exported',
            aggregateType: 'notice',
            aggregateId: (int) $notice->id,
            metadata: ['fields' => $fields, 'row_count' => count($rows)],
        );

        return [
            'fields' => $fields,
            'rows' => $rows,
            'row_count' => count($rows),
            'as_of' => $today,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTemplates(Principal $principal): array
    {
        return NoticeTemplate::query()
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->map(fn (NoticeTemplate $template): array => $this->templatePayload($template))
            ->all();
    }

    /**
     * @param  array{code: string, name: string, body_template: string, variables?: list<string>|null}  $data
     * @return array<string, mixed>
     */
    public function createTemplate(Principal $principal, array $data): array
    {
        $code = strtoupper(trim((string) $data['code']));
        $variables = array_values(array_unique(array_map('strval', (array) ($data['variables'] ?? []))));

        foreach ($variables as $variable) {
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $variable) !== 1) {
                throw new ValidationException(
                    ['variables' => ['Template variables must be simple identifiers.']],
                    'The given data was invalid.',
                    ['reason' => 'NOTICE_TEMPLATE_VARIABLE_INVALID'],
                );
            }
        }

        return DB::transaction(function () use ($principal, $data, $code, $variables): array {
            $latest = NoticeTemplate::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->orderByDesc('version')
                ->first();

            $template = NoticeTemplate::query()->create([
                'code' => $code,
                'name' => trim((string) $data['name']),
                'version' => $latest === null ? 1 : ((int) $latest->version) + 1,
                'body_template' => (string) $data['body_template'],
                'variables_json' => $variables,
                'status' => NoticeTemplate::STATUS_PUBLISHED,
                'created_by' => $principal->userId(),
            ]);

            $this->audit->record(
                actorUserId: $principal->userId(),
                action: 'engagement.notice_template.published',
                aggregateType: 'notice_template',
                aggregateId: (int) $template->id,
                after: ['code' => $code, 'version' => (int) $template->version],
            );

            return $this->templatePayload($template);
        });
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, as_of: string}
     */
    public function archived(Principal $principal, int $page, int $pageSize): array
    {
        $today = $this->businessDates->today()->value();
        $query = Notice::query()
            ->where('status', Notice::STATUS_ARCHIVED)
            ->orderByDesc('archived_at')
            ->orderByDesc('id');

        $total = (int) (clone $query)->count();
        $items = $query->forPage($page, $pageSize)->get()
            ->filter(fn (Notice $notice): bool => $this->policy->canManage($principal, $notice, $today))
            ->map(fn (Notice $notice): array => $this->noticesPayload($principal, $notice, $today))
            ->values()
            ->all();

        return ['items' => $items, 'total' => count($items), 'as_of' => $today];
    }

    /**
     * @return array<string, mixed>
     */
    public function archive(Principal $principal, int $noticeId, string $reason, string $businessDate): array
    {
        $today = $this->businessDates->resolve($businessDate !== '' ? $businessDate : null)->value();
        $notice = $this->manageable($principal, $noticeId, $today);

        if ($notice->status === Notice::STATUS_ARCHIVED) {
            return $this->noticesPayload($principal, $notice, $today);
        }

        $previous = (string) $notice->status;

        $notice->forceFill([
            'archive_previous_status' => $previous,
            'status' => Notice::STATUS_ARCHIVED,
            'archived_at' => $this->clock->now(),
            'archived_by' => $principal->userId(),
            'archive_reason' => trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null,
        ])->save();

        $this->audit->record(
            actorUserId: $principal->userId(),
            action: 'engagement.notice.archived',
            aggregateType: 'notice',
            aggregateId: (int) $notice->id,
            before: ['status' => $previous],
            after: ['status' => Notice::STATUS_ARCHIVED, 'reason' => $notice->archive_reason],
        );

        return $this->noticesPayload($principal, $notice->refresh(), $today);
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(Principal $principal, int $noticeId, string $businessDate): array
    {
        $today = $this->businessDates->resolve($businessDate !== '' ? $businessDate : null)->value();
        $notice = $this->manageable($principal, $noticeId, $today);

        if ($notice->status !== Notice::STATUS_ARCHIVED) {
            throw new ConflictException('Only an archived notice can be restored.', [
                'reason' => 'NOTICE_NOT_ARCHIVED',
                'status' => (string) $notice->status,
            ]);
        }

        $restored = (string) ($notice->archive_previous_status ?: Notice::STATUS_DRAFT);
        $notice->forceFill([
            'status' => $restored,
            'archived_at' => null,
            'archived_by' => null,
            'archive_previous_status' => null,
            'archive_reason' => null,
        ])->save();

        $this->audit->record(
            actorUserId: $principal->userId(),
            action: 'engagement.notice.restored',
            aggregateType: 'notice',
            aggregateId: (int) $notice->id,
            after: ['status' => $restored],
        );

        return $this->noticesPayload($principal, $notice->refresh(), $today);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function projection(array $items, int $total, string $asOf, int $page, int $pageSize, string $resource): ProjectionResult
    {
        return new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: '',
            partial: false,
            partialReason: null,
            drilldown: ['resource' => $resource],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function templatePayload(NoticeTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'code' => (string) $template->code,
            'name' => (string) $template->name,
            'version' => (int) $template->version,
            'body_template' => (string) $template->body_template,
            'variables' => array_values(array_map('strval', (array) $template->variables_json)),
            'status' => (string) $template->status,
        ];
    }

    private function manageable(Principal $principal, int $noticeId, string $today): Notice
    {
        $notice = $this->noticeOrFail($noticeId);

        if (! $this->policy->canManage($principal, $notice, $today)) {
            throw new NotFoundOrForbiddenException;
        }

        return $notice;
    }

    private function noticeOrFail(int $noticeId): Notice
    {
        $notice = Notice::query()
            ->whereKey($noticeId)
            ->first();

        if ($notice === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $notice;
    }

    /**
     * @return array<string, mixed>
     */
    private function noticesPayload(Principal $principal, Notice $notice, string $today): array
    {
        return [
            'id' => (int) $notice->id,
            'title' => (string) $notice->title,
            'summary' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $notice->body) ?? ''), 0, 120),
            'body' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $notice->body) ?: []))),
            'status' => (string) $notice->status,
            'audience' => (string) ($notice->audience_json['type'] ?? 'ALL'),
            'audienceLabel' => (string) ($notice->audience_json['label'] ?? ''),
            'orgUnitIds' => array_values(array_map('intval', (array) ($notice->audience_json['org_unit_ids'] ?? []))),
            'roleCodes' => array_values(array_map('strval', (array) ($notice->audience_json['role_codes'] ?? []))),
            'publishedAt' => $notice->publish_at?->toIso8601ZuluString(),
            'retractedAt' => $notice->retract_at?->toIso8601ZuluString(),
            'authorName' => null,
            'requiresAck' => (bool) $notice->requires_ack,
            'acknowledged' => false,
            'acknowledgementCount' => 0,
            'audienceCount' => (int) $notice->audience_count,
            'approvalCaseId' => $notice->approval_case_id !== null ? (int) $notice->approval_case_id : null,
            'approval_case_id' => $notice->approval_case_id !== null ? (int) $notice->approval_case_id : null,
            'approvedAt' => $notice->approved_at?->toIso8601ZuluString(),
            'archivedAt' => $notice->archived_at?->toIso8601ZuluString(),
            'templateCode' => $notice->template_code !== null ? (string) $notice->template_code : null,
            'templateVersion' => $notice->template_version !== null ? (int) $notice->template_version : null,
        ];
    }

    private function workflowPrincipal(Principal $principal): \App\Modules\Workflow\Contracts\WorkflowPrincipal
    {
        $resolver = app(\App\Modules\Workflow\Contracts\WorkflowPrincipalResolver::class);
        $workflowPrincipal = $resolver->resolve();

        if ($workflowPrincipal === null || $workflowPrincipal->userId !== $principal->userId()) {
            return new \App\Modules\Workflow\Contracts\WorkflowPrincipal(
                userId: $principal->userId(),
                employmentId: $principal->employmentId(),
                authVersion: $principal->authVersion(),
                isPrivileged: $principal->isPrivileged(),
                capabilities: ['engagement.notice.manage', 'engagement.notice.publish', 'workflow.case.create', 'workflow.case.decide'],
            );
        }

        return $workflowPrincipal;
    }
}
