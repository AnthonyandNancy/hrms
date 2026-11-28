<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateApplication;
use App\Modules\Talent\Models\CandidateConversion;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Requisition;
use App\Modules\Talent\Models\TalentPoolTag;
use App\Modules\Talent\Services\CandidateApplicationService;
use App\Modules\Talent\Services\RecruitmentService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RecruitmentController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly RecruitmentService $recruitment,
        private readonly CandidateApplicationService $applications,
        private readonly IdempotencyRunner $idempotency,
        private readonly OrganizationFacts $organization,
        private readonly SensitiveAccessAuditor $sensitiveAuditor,
    ) {}

    public function requisitions(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today()->value();
        if (! $this->authorizer->permits($principal, 'recruitment.requisition.read', $today)) {
            throw new AuthorizationException('Forbidden.');
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:24'],
            'search' => ['nullable', 'string', 'max:160'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 50);

        $query = $this->authorizer->constrain(
            Requisition::query(),
            $principal,
            'recruitment.requisition.read',
            $today,
        )
            ->when(isset($data['status']) && $data['status'] !== '', fn ($builder) => $builder->where('status', (string) $data['status']))
            ->when(isset($data['search']) && trim((string) $data['search']) !== '', function ($builder) use ($data): void {
                $term = '%'.trim((string) $data['search']).'%';
                $builder->where(function ($inner) use ($term): void {
                    $inner->where('title', 'like', $term)
                        ->orWhere('requisition_no', 'like', $term);
                });
            })
            ->orderBy('id');

        $total = (int) (clone $query)->count();
        $items = $query->forPage($page, $pageSize)->get()
            ->map(fn (Requisition $requisition): array => $this->requisitionData($requisition))
            ->values()
            ->all();

        return ApiResponse::ok(ApiResponse::list($items, $page, $pageSize, $total));
    }

    public function storeRequisition(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();

        $data = $request->validate([
            'org_unit_id' => ['required', 'integer', 'min:1'],
            'requisition_no' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'],
            'headcount' => ['required', 'integer', 'min:1', 'max:1000'],
            'budget_minor' => ['nullable', 'integer', 'min:0'],
            'budget_currency' => ['nullable', 'string', 'size:3'],
            'target_start_date' => ['nullable', new BusinessDateRule],
        ]);
        $this->allow($principal, 'recruitment.requisition.manage', AnchorRef::orgUnit((int) $data['org_unit_id']));

        return $this->command($request, 'requisition', $data, fn (): array => $this->recruitment->createRequisition($principal->userId(), $data, $today->value()));
    }

    public function updateRequisition(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();

        $data = $request->validate([
            'org_unit_id' => ['sometimes', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'max:160'],
            'headcount' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'budget_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'budget_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'target_start_date' => ['sometimes', 'nullable', new BusinessDateRule],
        ]);
        $existing = Requisition::query()->whereKey($id)->first();
        if ($existing === null) {
            throw NotFoundException::forResource();
        }
        $this->allow($principal, 'recruitment.requisition.manage', AnchorRef::orgUnit((int) $existing->org_unit_id));
        $targetOrgUnitId = isset($data['org_unit_id']) ? (int) $data['org_unit_id'] : (int) $existing->org_unit_id;
        if ($targetOrgUnitId !== (int) $existing->org_unit_id) {
            $this->allow($principal, 'recruitment.requisition.manage', AnchorRef::orgUnit($targetOrgUnitId));
        }

        return $this->command(
            $request,
            "requisition-{$id}-update",
            $data,
            fn (): array => $this->applyRequisitionUpdate($principal->userId(), $id, $data),
            path: "requisitions/{$id}",
            method: 'PATCH',
            createdStatus: 200,
        );
    }

    public function closeRequisition(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $existing = Requisition::query()->whereKey($id)->first();
        if ($existing === null) {
            throw NotFoundException::forResource();
        }
        $this->allow($principal, 'recruitment.requisition.manage', AnchorRef::orgUnit((int) $existing->org_unit_id));

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->command(
            $request,
            "requisition-{$id}-close",
            $data,
            fn (): array => $this->applyRequisitionClose($principal->userId(), $id, $data),
            path: "requisitions/{$id}/close",
            method: 'POST',
            createdStatus: 200,
        );
    }

    public function candidates(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today()->value();
        if (! $this->authorizer->permits($principal, 'recruitment.candidate.read', $today)) {
            throw new AuthorizationException('Forbidden.');
        }
        $companyWide = $this->authorizer->allows(
            $principal,
            'recruitment.candidate.read',
            AnchorRef::company(),
            $today,
        );
        $visibleCandidateIds = null;
        if (! $companyWide) {
            $visibleRequisitionIds = $this->visibleRequisitionIds($principal, 'recruitment.candidate.read', $today);
            $visibleCandidateIds = CandidateApplication::query()
                ->whereIn('requisition_id', $visibleRequisitionIds === [] ? [-1] : $visibleRequisitionIds)
                ->distinct()
                ->pluck('candidate_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        $data = $request->validate([
            'requisition_id' => ['nullable', 'integer', 'min:1'],
            'candidate_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:24'],
            'search' => ['nullable', 'string', 'max:120'],
            'talent_pool' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 100);
        $requisitionId = isset($data['requisition_id']) ? (int) $data['requisition_id'] : null;

        $candidateIdsForRequisition = null;

        if ($requisitionId !== null) {
            $candidateIdsForRequisition = CandidateApplication::query()
                ->where('requisition_id', $requisitionId)
                ->pluck('candidate_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $talentPoolOnly = ($data['talent_pool'] ?? false) === true;

        $query = Candidate::query()
            ->when($visibleCandidateIds !== null, fn ($builder) => $builder->whereIn(
                'id',
                $visibleCandidateIds === [] ? [-1] : $visibleCandidateIds,
            ))
            // Direct id lookup so a detail view never depends on the candidate
            // being inside the first page of the collection.
            ->when(isset($data['candidate_id']), fn ($builder) => $builder->whereKey((int) $data['candidate_id']))
            ->when($candidateIdsForRequisition !== null, fn ($builder) => $builder->whereIn('id', $candidateIdsForRequisition === [] ? [-1] : $candidateIdsForRequisition))
            ->when(isset($data['status']) && $data['status'] !== '', fn ($builder) => $builder->where('status', (string) $data['status']))
            ->when(isset($data['search']) && trim((string) $data['search']) !== '', fn ($builder) => $builder->where('display_name', 'like', '%'.trim((string) $data['search']).'%'))
            ->when($talentPoolOnly, fn ($builder) => $builder->whereIn(
                'id',
                CandidateApplication::query()->distinct()->pluck('candidate_id')->merge(
                    TalentPoolTag::query()->where('status', 'ACTIVE')->distinct()->pluck('candidate_id'),
                )->map(static fn (mixed $id): int => (int) $id)->all(),
            ))
            ->orderBy('id');

        $total = (int) (clone $query)->count();
        $items = $query->forPage($page, $pageSize)->get()
            ->map(fn (Candidate $candidate): array => $this->candidateData($candidate, $requisitionId))
            ->values()
            ->all();

        return ApiResponse::ok(ApiResponse::list($items, $page, $pageSize, $total));
    }

    public function applications(Request $request, int $candidateId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today()->value();
        $this->assertCandidateVisible($principal, $candidateId, 'recruitment.candidate.read', $today);
        $visibleRequisitionIds = $this->visibleRequisitionIds($principal, 'recruitment.candidate.read', $today);
        $items = array_values(array_filter(
            $this->applications->listForCandidate($candidateId),
            static fn (array $item): bool => in_array((int) ($item['requisition_id'] ?? 0), $visibleRequisitionIds, true),
        ));

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function storeApplication(Request $request, int $candidateId): JsonResponse
    {
        $principal = $this->principals->resolve();

        $data = $request->validate([
            'requisition_id' => ['required', 'integer', 'min:1'],
            'opening_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:64'],
        ]);
        $requisition = Requisition::query()->whereKey((int) $data['requisition_id'])->first();
        if ($requisition === null) {
            throw NotFoundException::forResource();
        }
        $this->allow($principal, 'recruitment.candidate.manage', AnchorRef::orgUnit((int) $requisition->org_unit_id));

        return $this->command(
            $request,
            "candidate-{$candidateId}-application",
            $data,
            fn (): array => $this->applications->store($principal->userId(), $candidateId, $data),
            path: "candidates/{$candidateId}/applications",
            method: 'POST',
        );
    }

    public function moveCandidateStage(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today()->value();
        $this->assertCandidateVisible($principal, $id, 'recruitment.candidate.manage', $today);

        $data = $request->validate([
            'status' => ['required', 'string', 'max:24'],
            'application_id' => ['nullable', 'integer', 'min:1'],
            'requisition_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->command(
            $request,
            "candidate-{$id}-stage",
            $data,
            fn (): array => $this->applications->moveStage($principal->userId(), $id, $data),
            path: "candidates/{$id}/stage",
            method: 'PATCH',
            createdStatus: 200,
        );
    }

    public function interviews(Request $request, int $candidateId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today()->value();
        $this->assertCandidateVisible($principal, $candidateId, 'recruitment.candidate.read', $today);
        $visibleRequisitionIds = $this->visibleRequisitionIds($principal, 'recruitment.candidate.read', $today);

        $candidate = Candidate::query()->whereKey($candidateId)->first();

        if ($candidate === null) {
            throw NotFoundException::forResource();
        }

        $canSeeFeedback = $this->authorizer->permits($principal, 'recruitment.evaluation.manage', $today);

        $items = [];

        foreach (Interview::query()
            ->where('candidate_id', $candidateId)
            ->whereIn('requisition_id', $visibleRequisitionIds === [] ? [-1] : $visibleRequisitionIds)
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->get() as $interview) {
            $data = [
                'interview_id' => (int) $interview->id,
                'candidate_id' => (int) $interview->candidate_id,
                'requisition_id' => (int) $interview->requisition_id,
                'scheduled_at' => $this->iso($interview->getAttribute('scheduled_at')),
                'duration_minutes' => (int) $interview->duration_minutes,
                'interviewer' => (string) $interview->interviewer,
                'mode' => (string) $interview->mode,
                'status' => (string) $interview->status,
                'feedback_restricted' => ! $canSeeFeedback,
            ];

            $application = $this->applicationFor($candidateId, (int) $interview->requisition_id);

            $data['application_id'] = $application !== null ? (int) $application->id : null;
            $data['application_stage'] = $application !== null ? (string) $application->stage : null;

            if ($canSeeFeedback) {
                $data['feedback'] = $interview->feedback;
                $data['recommendation'] = $interview->recommendation;

                $fields = [];
                if ($interview->feedback !== null && $interview->feedback !== '') {
                    $fields[] = 'feedback';
                }
                if ($interview->recommendation !== null && $interview->recommendation !== '') {
                    $fields[] = 'recommendation';
                }

                if ($fields !== []) {
                    $this->sensitiveAuditor->record(
                        userId: $principal->userId(),
                        capabilityCode: 'recruitment.evaluation.manage',
                        resourceType: 'candidate_interview',
                        resourceId: (int) $interview->id,
                        fields: $fields,
                        context: ['access' => 'DETAIL'],
                    );
                }
            }

            $items[] = $data;
        }

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function storeCandidate(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        if (! $this->authorizer->permits($principal, 'recruitment.candidate.manage', $this->businessDates->today()->value())) {
            throw new AuthorizationException('Forbidden.');
        }

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:254'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->command($request, 'candidate', $data, fn (): array => $this->recruitment->createCandidate($principal->userId(), $data));
    }

    public function storeInterview(Request $request, int $candidateId): JsonResponse
    {
        $principal = $this->principals->resolve();

        $data = $request->validate([
            'requisition_id' => ['required', 'integer', 'min:1'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'interviewer' => ['required', 'string', 'max:120'],
            'mode' => ['nullable', 'string', 'in:VIDEO,ONSITE,PHONE'],
            'feedback' => ['nullable', 'string', 'max:4000'],
            'recommendation' => ['nullable', 'string', 'in:STRONG_YES,YES,NO,STRONG_NO'],
        ]);
        $requisition = Requisition::query()->whereKey((int) $data['requisition_id'])->first();
        if ($requisition === null) {
            throw NotFoundException::forResource();
        }
        $this->allow($principal, 'recruitment.interview.manage', AnchorRef::orgUnit((int) $requisition->org_unit_id));

        return $this->command($request, "candidate-{$candidateId}-interview", $data, fn (): array => $this->recruitment->createInterview($principal->userId(), $candidateId, $data));
    }

    public function offers(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'recruitment.offer.manage', AnchorRef::company());
        $data = $request->validate([
            'candidate_id' => ['nullable', 'integer', 'min:1'],
            'requisition_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:24'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::ok($this->recruitment->offers(
            [
                'candidate_id' => $data['candidate_id'] ?? null,
                'requisition_id' => $data['requisition_id'] ?? null,
                'status' => $data['status'] ?? null,
            ],
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 20),
        ));
    }

    public function storeOffer(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->allow($principal, 'recruitment.offer.manage', AnchorRef::company());

        $data = $request->validate([
            'candidate_id' => ['required', 'integer', 'min:1'],
            'requisition_id' => ['required', 'integer', 'min:1'],
            'base_salary_minor' => ['required', 'integer', 'min:0'],
            'salary_currency' => ['required', 'string', 'size:3'],
            'salary_scheme_code' => ['nullable', 'string', 'max:64'],
            'start_date' => ['required', new BusinessDateRule],
        ]);

        return $this->command($request, 'offer', $data, fn (): array => $this->recruitment->createOffer($principal->userId(), $data, $today->value()));
    }

    public function acceptOffer(Request $request, int $offerId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->allow($principal, 'recruitment.offer.accept', AnchorRef::company());

        $data = $request->validate([
            'org_unit_id' => ['required', 'integer', 'min:1'],
            'employee_no' => ['required', 'string', 'max:64'],
            'hire_date' => ['nullable', new BusinessDateRule],
        ]);

        return $this->command($request, "offer-{$offerId}-accept", $data, fn (): array => $this->recruitment->acceptOffer($principal->userId(), $offerId, $data, $today->value()));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyRequisitionUpdate(int $actorUserId, int $id, array $data): array
    {
        $requisition = Requisition::query()->whereKey($id)->first();

        if ($requisition === null) {
            throw NotFoundException::forResource();
        }

        if ((string) $requisition->status === 'CLOSED') {
            throw new ConflictException('A closed requisition cannot be edited.', [
                'reason' => 'REQUISITION_CLOSED',
            ]);
        }

        $before = $this->requisitionData($requisition);
        $attributes = [];

        foreach (['org_unit_id', 'title', 'headcount', 'budget_minor', 'budget_currency', 'target_start_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $field === 'budget_currency' && $data[$field] !== null
                    ? mb_strtoupper((string) $data[$field])
                    : $data[$field];
            }
        }

        if ($attributes !== []) {
            $requisition->forceFill($attributes)->save();
        }

        $fresh = $requisition->fresh() ?? $requisition;
        $after = $this->requisitionData($fresh);

        app(AuditRecorder::class)->record(
            actorUserId: $actorUserId,
            action: 'talent.requisition.updated',
            aggregateType: 'recruitment_requisition',
            aggregateId: (int) $fresh->id,
            before: $before,
            after: $after,
        );

        return [...$after, 'replayed' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyRequisitionClose(int $actorUserId, int $id, array $data): array
    {
        $requisition = Requisition::query()->whereKey($id)->lockForUpdate()->first();

        if ($requisition === null) {
            throw NotFoundException::forResource();
        }

        if ((string) $requisition->status === 'CLOSED') {
            return [...$this->requisitionData($requisition), 'replayed' => true];
        }

        $before = $this->requisitionData($requisition);
        $requisition->forceFill(['status' => 'CLOSED'])->save();

        app(AuditRecorder::class)->record(
            actorUserId: $actorUserId,
            action: 'talent.requisition.closed',
            aggregateType: 'recruitment_requisition',
            aggregateId: (int) $requisition->id,
            before: $before,
            after: $this->requisitionData($requisition),
            metadata: ['reason' => $data['reason'] ?? null],
        );

        return [...$this->requisitionData($requisition->fresh() ?? $requisition), 'replayed' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  \Closure(): array<string, mixed>  $operation
     */
    private function command(
        Request $request,
        string $scope,
        array $data,
        \Closure $operation,
        string $path = '',
        string $method = 'POST',
        int $createdStatus = 201,
    ): JsonResponse {
        $principal = $this->principals->resolve();
        $key = (string) $request->header('Idempotency-Key', '');

        if ($key === '') {
            throw new ValidationException(['idempotency_key' => ['A valid Idempotency-Key header is required.']]);
        }

        $idempotencyPath = $path !== '' ? $path : $scope;
        $httpStatus = $createdStatus;

        $result = DB::transaction(fn (): \App\Modules\Platform\Contracts\IdempotentResult => $this->idempotency->run(
            principalId: $principal->userId(),
            scope: $method.':/v1/recruitment/'.$idempotencyPath,
            key: $key,
            payload: $data,
            operation: function () use ($operation, $httpStatus): array {
                $body = $operation();

                return [
                    'http_status' => $httpStatus,
                    'body' => $body,
                    'resource_type' => 'talent',
                    'resource_id' => (string) ($body['candidate_id'] ?? $body['offer_id'] ?? $body['requisition_id'] ?? ''),
                ];
            },
        ));

        /** @var array<string, mixed> $body */
        $body = $result->body;
        $businessReplayed = ($body['replayed'] ?? false) === true;
        $response = $result->replayed || $businessReplayed || $createdStatus === 200
            ? ApiResponse::ok($body)
            : ApiResponse::created($body);

        if ($result->replayed || $businessReplayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function requisitionData(Requisition $requisition): array
    {
        $orgUnit = $this->organization->orgUnit((int) $requisition->org_unit_id);

        return [
            'requisition_id' => (int) $requisition->id,
            'requisition_no' => (string) $requisition->requisition_no,
            'title' => (string) $requisition->title,
            'org_unit_id' => (int) $requisition->org_unit_id,
            'org_unit_name' => $orgUnit?->name,
            'status' => (string) $requisition->status,
            'headcount' => (int) $requisition->headcount,
            'budget_minor' => $requisition->budget_minor !== null ? (int) $requisition->budget_minor : null,
            'budget_currency' => $requisition->budget_currency !== null ? (string) $requisition->budget_currency : null,
            'target_start_date' => $requisition->target_start_date !== null ? (string) $requisition->target_start_date : null,
            'opened_at' => $this->iso($requisition->getAttribute('recorded_at')),
            'case_id' => $requisition->case_id !== null ? (int) $requisition->case_id : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateData(Candidate $candidate, ?int $requisitionId = null): array
    {
        $interview = $requisitionId === null ? null : Interview::query()
            ->where('candidate_id', (int) $candidate->id)
            ->where('requisition_id', $requisitionId)
            ->orderByDesc('id')
            ->first();
        $offer = $requisitionId === null ? null : Offer::query()
            ->where('candidate_id', (int) $candidate->id)
            ->where('requisition_id', $requisitionId)
            ->orderByDesc('version')
            ->first();

        if ($requisitionId === null) {
            $interview = Interview::query()
                ->where('candidate_id', (int) $candidate->id)
                ->orderByDesc('id')
                ->first();
            $offer = Offer::query()
                ->where('candidate_id', (int) $candidate->id)
                ->orderByDesc('version')
                ->first();
        }

        $conversion = CandidateConversion::query()
            ->where('candidate_id', (int) $candidate->id)
            ->first();

        $this->sensitiveAuditor->record(
            userId: $this->principals->resolve()->userId(),
            capabilityCode: 'recruitment.candidate.read',
            resourceType: 'candidate',
            resourceId: (int) $candidate->id,
            fields: array_values(array_filter([
                $candidate->email_hash !== null ? 'email' : null,
                $candidate->mobile_hash !== null ? 'mobile' : null,
            ])),
            context: ['access' => 'LIST', 'minimized' => true],
        );

        $application = $requisitionId === null
            ? CandidateApplication::query()->where('candidate_id', (int) $candidate->id)->orderByDesc('id')->first()
            : CandidateApplication::query()->where('candidate_id', (int) $candidate->id)->where('requisition_id', $requisitionId)->first();

        $linkedRequisitionId = $requisitionId
            ?? ($application !== null ? (int) $application->requisition_id : ($interview !== null ? (int) $interview->requisition_id : ($offer !== null ? (int) $offer->requisition_id : null)));

        return [
            'candidate_id' => (int) $candidate->id,
            'display_name' => (string) $candidate->display_name,
            'status' => (string) $candidate->status,
            'application_id' => $application !== null ? (int) $application->id : null,
            'application_stage' => $application !== null ? (string) $application->stage : null,
            'has_email' => $candidate->email_hash !== null,
            'has_mobile' => $candidate->mobile_hash !== null,
            'source' => $candidate->source,
            'requisition_id' => $linkedRequisitionId,
            'onboarding_case_id' => $offer !== null && $offer->onboarding_case_id !== null ? (int) $offer->onboarding_case_id : null,
            'conversion_status' => $conversion !== null ? 'CONVERTED' : ($offer !== null && $offer->onboarding_case_id !== null ? 'PENDING_ONBOARDING' : null),
            'offer_id' => $offer !== null ? (int) $offer->id : null,
            'offer_status' => $offer !== null ? (string) $offer->status : null,
            'base_salary_minor' => $offer !== null ? (int) $offer->base_salary_minor : null,
            'salary_currency' => $offer !== null ? (string) $offer->salary_currency : null,
            'offer_start_date' => $offer !== null ? (string) $offer->start_date : null,
            'next_interview_at' => $interview === null ? null : $this->iso($interview->getAttribute('scheduled_at')),
            'converted_employment_id' => $conversion !== null ? (int) $conversion->employment_id : null,
            'converted_employee_no' => $conversion !== null ? (string) $conversion->employee_no : null,
            'recorded_at' => $this->iso($candidate->getAttribute('recorded_at')),
        ];
    }

    private function applicationFor(int $candidateId, ?int $requisitionId = null): ?CandidateApplication
    {
        return CandidateApplication::query()
            ->where('candidate_id', $candidateId)
            ->when($requisitionId !== null, fn ($query) => $query->where('requisition_id', $requisitionId))
            ->orderByDesc('id')
            ->first();
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return \Carbon\CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }

    /**
     * @return list<int>
     */
    private function visibleRequisitionIds(Principal $principal, string $capability, string $businessDate): array
    {
        return $this->authorizer
            ->constrain(Requisition::query(), $principal, $capability, $businessDate)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function assertCandidateVisible(
        Principal $principal,
        int $candidateId,
        string $capability,
        string $businessDate,
    ): void {
        if (! $this->authorizer->permits($principal, $capability, $businessDate)) {
            throw new AuthorizationException('Forbidden.');
        }
        if ($this->authorizer->allows($principal, $capability, AnchorRef::company(), $businessDate)) {
            return;
        }

        $visibleRequisitionIds = $this->visibleRequisitionIds($principal, $capability, $businessDate);
        if ($visibleRequisitionIds === []) {
            throw new NotFoundOrForbiddenException;
        }

        $visibleApplication = CandidateApplication::query()
            ->where('candidate_id', $candidateId)
            ->whereIn('requisition_id', $visibleRequisitionIds)
            ->exists();
        $visibleInterview = Interview::query()
            ->where('candidate_id', $candidateId)
            ->whereIn('requisition_id', $visibleRequisitionIds)
            ->exists();

        if (! $visibleApplication && ! $visibleInterview) {
            throw new NotFoundOrForbiddenException;
        }
    }

    private function allow(Principal $principal, string $capability, AnchorRef $anchor): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->allows($principal, $capability, $anchor, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
