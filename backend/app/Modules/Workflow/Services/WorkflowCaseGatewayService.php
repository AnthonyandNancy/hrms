<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Modules\Workflow\Contracts\WorkflowCaseRecord;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\WorkflowTemplate;
use App\Shared\Exceptions\NotFoundException;

final class WorkflowCaseGatewayService implements WorkflowCaseGateway
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseQueryService $queries,
        private readonly TemplateService $templates,
    ) {}

    public function createCase(
        int $requesterUserId,
        string $type,
        string $subjectType,
        ?int $subjectId,
        string $title,
        array $payload,
        string $templateCode,
        string $businessDate,
        bool $submit,
    ): WorkflowCaseRecord {
        $templateId = $this->latestTemplateId($templateCode);

        if ($templateId === null) {
            throw new NotFoundException("No published workflow template exists for {$templateCode}.");
        }

        $principal = new WorkflowPrincipal(
            userId: $requesterUserId,
            employmentId: null,
            authVersion: 1,
            isPrivileged: false,
        );

        $case = $this->cases->create(
            principal: $principal,
            type: $type,
            subjectType: $subjectType,
            subjectId: $subjectId,
            title: $title,
            payload: $payload,
            templateId: $templateId,
            businessDate: $businessDate,
            submit: $submit,
        );

        return $this->record($case);
    }

    public function ensureTemplate(string $code, string $name, array $definition): int
    {
        $existing = WorkflowTemplate::query()
            ->where('code', $code)
            ->where('status', 'PUBLISHED')
            ->orderByDesc('version')
            ->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        $version = ((int) WorkflowTemplate::query()
            ->where('code', $code)
            ->max('version')) + 1;

        $template = $this->templates->create(0, [
            'code' => $code,
            'name' => $name,
            'version' => max(1, $version),
            'status' => 'PUBLISHED',
            'definition' => $definition,
        ]);

        return (int) $template->id;
    }

    public function caseFor(int $caseId): ?WorkflowCaseRecord
    {
        $case = CaseModel::query()
            ->whereKey($caseId)
            ->first();

        return $case !== null ? $this->record($case) : null;
    }

    public function projection(int $caseId): ?array
    {
        $case = CaseModel::query()
            ->whereKey($caseId)
            ->first();

        if ($case === null) {
            return null;
        }

        $principal = new WorkflowPrincipal(
            userId: (int) $case->requester_user_id,
            employmentId: null,
            authVersion: 1,
            isPrivileged: false,
            capabilities: ['workflow.case.admin'],
        );

        return $this->queries->detail($principal, $caseId);
    }

    private function latestTemplateId(string $code): ?int
    {
        $id = WorkflowTemplate::query()
            ->where('code', $code)
            ->where('status', 'PUBLISHED')
            ->orderByDesc('version')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function record(CaseModel $case): WorkflowCaseRecord
    {
        return new WorkflowCaseRecord(
            id: (int) $case->id,
            caseNo: (string) $case->case_no,
            type: (string) $case->type,
            subjectType: (string) $case->subject_type,
            subjectId: $case->subject_id !== null ? (int) $case->subject_id : null,
            title: (string) $case->title,
            payload: (array) $case->payload_json,
            applicationStatus: $case->status->value,
            effectStatus: $case->effect_status->value,
            businessDate: (string) $case->business_date,
        );
    }
}
