<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Enums\DecisionAction;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Effect;
use App\Modules\Workflow\Models\WorkflowTemplate;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Support\Facades\DB;

/**
 * Workflow-owned implementation of the business-module case command port. The
 * template is resolved by case type so callers never need to know template ids.
 */
final class CaseCommandService implements CaseCommandPort
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly DecisionService $decisions,
        private readonly CaseQueryService $queries,
    ) {}

    public function open(
        WorkflowPrincipal $principal,
        string $type,
        string $subjectType,
        ?int $subjectId,
        string $title,
        array $payload,
        string $businessDate,
        bool $submit = true,
    ): array {
        $templateCode = CaseTemplateCatalog::templateCodeFor($type);

        $template = $templateCode === null ? null : WorkflowTemplate::query()
            ->where('code', $templateCode)
            ->where('status', 'PUBLISHED')
            ->orderByDesc('version')
            ->first();

        if ($template === null) {
            throw new ValidationException(
                ['type' => ['No published workflow template exists for this case type.']],
                'No published workflow template exists for this case type.',
            );
        }

        $case = $this->cases->create(
            principal: $principal,
            type: $type,
            subjectType: $subjectType,
            subjectId: $subjectId,
            title: $title,
            payload: $payload,
            templateId: (int) $template->id,
            businessDate: $businessDate,
            submit: $submit,
        );

        return $this->summary($case);
    }

    public function decide(
        WorkflowPrincipal $principal,
        int $caseId,
        string $action,
        string $comment,
        ?string $reason = null,
    ): array {
        $decision = DecisionAction::tryFrom(strtoupper($action));

        if ($decision === null || ! in_array($decision, [DecisionAction::APPROVE, DecisionAction::REJECT], true)) {
            throw new ValidationException(
                ['action' => ['Only APPROVE or REJECT decisions are supported for business work items.']],
                'The decision action is not supported.',
            );
        }

        $case = $this->decisions->decide(
            principal: $principal,
            caseId: $caseId,
            action: $decision,
            comment: $comment,
            targetUserId: null,
            reason: $reason,
        );

        $data = $this->queries->detail($principal, (int) $case->id);

        return [
            'case_id' => (int) $case->id,
            'application_status' => (string) $data['application_status'],
            'effect_status' => (string) $data['effect_status'],
        ];
    }

    public function withdraw(WorkflowPrincipal $principal, int $caseId, string $reason): array
    {
        $case = CaseModel::query()
            ->whereKey($caseId)
            ->first();

        if ($case === null || (int) $case->requester_user_id !== $principal->userId) {
            throw NotFoundException::forResource();
        }

        if (in_array((string) $case->status->value, ['WITHDRAWN', 'CANCELLED'], true)) {
            return $this->summary($case);
        }

        $withdrawn = $this->cases->withdraw($principal, $case, $reason);

        return $this->summary($withdrawn);
    }

    /**
     * @return array{case_id: int, effect_status: string, reversed: int}
     */
    public function reverseEffect(
        WorkflowPrincipal $principal,
        int $caseId,
        string $reason,
        string $effectiveOn,
    ): array {
        return DB::transaction(function () use ($principal, $caseId, $reason, $effectiveOn): array {
            $case = CaseModel::query()
                ->whereKey($caseId)
                ->lockForUpdate()
                ->first();

            if ($case === null) {
                throw NotFoundException::forResource();
            }

            if ((int) $case->requester_user_id !== $principal->userId
                && ! $principal->can('workflow.case.admin')) {
                throw NotFoundException::forResource();
            }

            $effects = Effect::query()
                ->where('case_id', $caseId)
                ->whereNull('reversal_of_id')
                ->where('status', \App\Modules\Workflow\Enums\EffectStatus::APPLIED->value)
                ->orderBy('id')
                ->get();

            if ($effects->isEmpty()) {
                return [
                    'case_id' => $caseId,
                    'effect_status' => $case->effect_status->value,
                    'reversed' => 0,
                ];
            }

            $reversed = 0;

            foreach ($effects as $effect) {
                $result = app(EffectExecutor::class)->reverse((int) $effect->id, $reason, $effectiveOn);

                if ($result->status !== \App\Modules\Workflow\Enums\EffectStatus::REVERSED) {
                    throw new ConflictException('The Workflow effect reversal failed and remains retryable.', [
                        'reason' => 'EFFECT_REVERSAL_FAILED',
                        'effect_id' => (int) $effect->id,
                        'effect_type' => (string) $effect->effect_type,
                        'effect_status' => $result->status->value,
                    ]);
                }

                $reversed++;
            }

            $case->refresh();

            return [
                'case_id' => $caseId,
                'effect_status' => $case->effect_status->value,
                'reversed' => $reversed,
            ];
        });
    }

    public function find(int $caseId): ?array
    {
        $case = CaseModel::query()
            ->whereKey($caseId)
            ->first();

        if ($case === null) {
            return null;
        }

        $case->loadMissing(['instance', 'tasks']);
        $pending = $case->tasks->firstWhere('status', \App\Modules\Workflow\Enums\TaskStatus::PENDING);

        return [
            'id' => (int) $case->id,
            'case_no' => (string) $case->case_no,
            'title' => (string) $case->title,
            'status' => $case->status->value,
            'effect_status' => $case->effect_status->value,
            'requester_user_id' => (int) $case->requester_user_id,
            'type' => (string) $case->type,
            'subject_type' => (string) $case->subject_type,
            'subject_id' => $case->subject_id !== null ? (int) $case->subject_id : null,
            'payload' => (array) $case->payload_json,
            'business_date' => (string) $case->business_date,
            'submitted_at' => $case->submitted_at?->toIso8601ZuluString(),
            'current_node' => $case->instance?->current_node_key,
            'due_at' => $pending?->due_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{id: int, case_no: string, status: string, effect_status: string, template_id: int|null}
     */
    private function summary(CaseModel $case): array
    {
        return [
            'id' => (int) $case->id,
            'case_no' => (string) $case->case_no,
            'status' => $case->status->value,
            'effect_status' => $case->effect_status->value,
            'template_id' => $case->template_id !== null ? (int) $case->template_id : null,
        ];
    }
}
