<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Enums\EffectStatus;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Effect;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Observability\AuditRecorder;

/**
 * CommandRunner entry points for the Workflow writes.
 *
 * Authorization still belongs to route middleware; this service owns the
 * transaction-wide invariants, idempotency keys and audit evidence. Domain
 * services (CaseService, DecisionService, EffectExecutor, TemplateService)
 * keep their state machines; wrapping them here prevents the HTTP layer from
 * bypassing idempotency or audit.
 */
final class WorkflowCommandService
{
    public function __construct(
        private readonly CommandRunner $commands,
        private readonly AuditRecorder $audit,
        private readonly CaseService $cases,
        private readonly DecisionService $decisions,
        private readonly EffectExecutor $effects,
        private readonly TemplateService $templates,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function createCase(WorkflowPrincipal $principal, array $input, string $idempotencyKey): CommandResult
    {
        $payload = [
            'type' => (string) $input['type'],
            'subject_type' => (string) $input['subject_type'],
            'subject_id' => isset($input['subject_id']) ? (int) $input['subject_id'] : null,
            'title' => (string) $input['title'],
            'payload' => (array) $input['payload'],
            'template_id' => (int) $input['template_id'],
            'business_date' => (string) $input['business_date'],
            'submit' => (bool) ($input['submit'] ?? false),
        ];

        return $this->commands->run(
            new CommandDefinition(
                name: 'workflow.case.create',
                capability: 'workflow.case.create',
                anchor: AnchorType::SELF,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'workflow.case.created',
            ),
            $payload,
            function () use ($principal, $payload): array {
                $case = $this->cases->create(
                    principal: $principal,
                    type: $payload['type'],
                    subjectType: $payload['subject_type'],
                    subjectId: $payload['subject_id'],
                    title: $payload['title'],
                    payload: $payload['payload'],
                    templateId: $payload['template_id'],
                    businessDate: $payload['business_date'],
                    submit: $payload['submit'],
                );

                $this->audit->record(
                    $principal->userId,
                    'workflow.case.created',
                    'case',
                    $case->id,
                    after: [
                        'case_no' => $case->case_no,
                        'type' => $case->type,
                        'application_status' => $case->status->value,
                        'template_version' => $case->template_version,
                        'submitted' => $payload['submit'],
                    ],
                );

                return [
                    'http_status' => 201,
                    'body' => [
                        'id' => $case->id,
                        'case_no' => $case->case_no,
                        'application_status' => $case->status->value,
                        'effect_status' => $case->effect_status->value,
                        'template_version' => $case->template_version,
                    ],
                    'resource_type' => 'case',
                    'resource_id' => (string) $case->id,
                ];
            },
            $idempotencyKey,
        );
    }

    public function submitCase(WorkflowPrincipal $principal, CaseModel $case, string $idempotencyKey): CommandResult
    {
        return $this->commands->run(
            new CommandDefinition(
                name: 'workflow.case.submit.'.$case->id,
                capability: 'workflow.case.create',
                anchor: AnchorType::CASE,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'workflow.case.submitted',
            ),
            ['case_id' => (int) $case->id],
            function () use ($principal, $case): array {
                $before = $case->status->value;
                $submitted = $this->cases->submit($principal, $case);

                $this->audit->record(
                    $principal->userId,
                    'workflow.case.submitted',
                    'case',
                    $submitted->id,
                    before: ['application_status' => $before],
                    after: [
                        'application_status' => $submitted->status->value,
                        'current_node_key' => $submitted->instance?->current_node_key,
                    ],
                );

                return [
                    'http_status' => 200,
                    'body' => [
                        'id' => $submitted->id,
                        'application_status' => $submitted->status->value,
                        'effect_status' => $submitted->effect_status->value,
                    ],
                    'resource_type' => 'case',
                    'resource_id' => (string) $submitted->id,
                ];
            },
            $idempotencyKey,
        );
    }

    public function withdrawCase(WorkflowPrincipal $principal, CaseModel $case, string $comment, string $idempotencyKey): CommandResult
    {
        return $this->commands->run(
            new CommandDefinition(
                name: 'workflow.case.withdraw.'.$case->id,
                capability: 'workflow.case.create',
                anchor: AnchorType::CASE,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'workflow.case.withdrawn',
            ),
            ['case_id' => (int) $case->id, 'comment' => $comment],
            function () use ($principal, $case, $comment): array {
                $before = $case->status->value;
                $withdrawn = $this->cases->withdraw($principal, $case, $comment);

                // The human comment is evidence for the case timeline, not an
                // audit payload: audit rows stay IDs and states only.
                $this->audit->record(
                    $principal->userId,
                    'workflow.case.withdrawn',
                    'case',
                    $withdrawn->id,
                    before: ['application_status' => $before],
                    after: ['application_status' => $withdrawn->status->value],
                );

                return [
                    'http_status' => 200,
                    'body' => [
                        'id' => $withdrawn->id,
                        'application_status' => $withdrawn->status->value,
                        'effect_status' => $withdrawn->effect_status->value,
                    ],
                    'resource_type' => 'case',
                    'resource_id' => (string) $withdrawn->id,
                ];
            },
            $idempotencyKey,
        );
    }

    /**
     * @param  callable(CaseModel): array<string, mixed>  $resultBody
     */
    public function decide(
        WorkflowPrincipal $principal,
        int $caseId,
        \App\Modules\Workflow\Enums\DecisionAction $action,
        string $comment,
        ?int $targetUserId,
        ?string $reason,
        string $idempotencyKey,
        callable $resultBody,
    ): CommandResult {
        $payload = [
            'action' => $action->value,
            'comment' => $comment,
            'target_user_id' => $targetUserId,
            'reason' => $reason,
        ];

        return $this->commands->run(
            new CommandDefinition(
                name: 'workflow.case.decide.'.$caseId,
                capability: 'workflow.case.decide',
                anchor: AnchorType::CASE,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'workflow.decision.recorded',
            ),
            $payload,
            function () use ($principal, $caseId, $action, $comment, $targetUserId, $reason, $resultBody): array {
                $case = $this->decisions->decide(
                    principal: $principal,
                    caseId: $caseId,
                    action: $action,
                    comment: $comment,
                    targetUserId: $targetUserId,
                    reason: $reason,
                );

                $decision = \App\Modules\Workflow\Models\Decision::query()
                    ->where('case_id', $case->id)
                    ->latest('id')
                    ->first();

                // The decision table is immutable evidence; the audit row is
                // the cross-module query surface. Keep it ID/state-only and
                // leave comments/reasons in their bounded domain fields.
                $this->audit->record(
                    $principal->userId,
                    'workflow.decision.recorded',
                    'case',
                    $case->id,
                    after: [
                        'decision_id' => $decision?->id,
                        'action' => $action->value,
                        'application_status' => $case->status->value,
                        'effect_status' => $case->effect_status->value,
                    ],
                );

                return [
                    'http_status' => 200,
                    'body' => (array) $resultBody($case),
                    'resource_type' => 'case',
                    'resource_id' => (string) $case->id,
                ];
            },
            $idempotencyKey,
        );
    }

    public function retryEffect(WorkflowPrincipal $principal, Effect $effect, string $reason, string $idempotencyKey): CommandResult
    {
        return $this->commands->run(
            new CommandDefinition(
                name: 'workflow.effect.retry.'.$effect->id,
                capability: 'workflow.case.admin',
                anchor: AnchorType::CASE,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'workflow.effect.retry_requested',
            ),
            ['effect_id' => (int) $effect->id, 'reason' => $reason],
            function () use ($principal, $effect, $reason): array {
                $this->effects->retry($effect->id, $principal, $reason);
                $fresh = $effect->fresh() ?? $effect;

                return [
                    'http_status' => $fresh->status === EffectStatus::APPLIED ? 200 : 202,
                    'body' => [
                        'effect_id' => $fresh->id,
                        'case_id' => $fresh->case_id,
                        'effect_status' => $fresh->status->value,
                        'attempts' => $fresh->attempts,
                        'requires_escalation' => $fresh->requires_escalation,
                        'reason' => $reason,
                    ],
                    'resource_type' => 'effect',
                    'resource_id' => (string) $fresh->id,
                ];
            },
            $idempotencyKey,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createTemplate(WorkflowPrincipal $principal, array $input, string $idempotencyKey): CommandResult
    {
        $payload = [
            'code' => (string) $input['code'],
            'name' => (string) $input['name'],
            'version' => (int) $input['version'],
            'status' => (string) ($input['status'] ?? 'DRAFT'),
            'definition' => (array) $input['definition'],
        ];

        return $this->commands->run(
            new CommandDefinition(
                name: 'workflow.template.version.create.'.$input['code'].'.'.$input['version'],
                capability: 'workflow.case.admin',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'workflow.template.version.created',
            ),
            $payload,
            function () use ($principal, $payload): array {
                $template = $this->templates->create($principal->userId, $payload);

                $this->audit->record(
                    $principal->userId,
                    'workflow.template.version.created',
                    'workflow_template',
                    $template->id,
                    after: [
                        'code' => $template->code,
                        'version' => $template->version,
                        'status' => $template->status,
                    ],
                );

                return [
                    'http_status' => 201,
                    'body' => [
                        'id' => $template->id,
                        'code' => $template->code,
                        'name' => $template->name,
                        'version' => $template->version,
                        'status' => $template->status,
                        'created_at' => $template->created_at?->toIso8601ZuluString(),
                    ],
                    'resource_type' => 'workflow_template',
                    'resource_id' => (string) $template->id,
                ];
            },
            $idempotencyKey,
        );
    }
}
