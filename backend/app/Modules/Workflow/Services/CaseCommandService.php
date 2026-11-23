<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\WorkflowTemplate;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;

/**
 * Workflow-owned implementation of the business-module case command port. The
 * template is resolved by case type so callers never need to know template ids.
 */
final class CaseCommandService implements CaseCommandPort
{
    public function __construct(
        private readonly CaseService $cases,
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
            ->where('tenant_id', $principal->tenantId)
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

    public function withdraw(WorkflowPrincipal $principal, int $caseId, string $reason): array
    {
        $case = CaseModel::query()
            ->where('tenant_id', $principal->tenantId)
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

    public function find(int $tenantId, int $caseId): ?array
    {
        $case = CaseModel::query()
            ->where('tenant_id', $tenantId)
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
