<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Modules\Talent\Services\RecruitmentService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\ValidationException;
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
        private readonly IdempotencyRunner $idempotency,
    ) {}

    public function requisitions(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'recruitment.requisition.read', AnchorRef::company());

        $items = \App\Modules\Talent\Models\Requisition::query()
            ->orderBy('id')
            ->get()
            ->map(static fn (\App\Modules\Talent\Models\Requisition $requisition): array => [
                'requisition_id' => (int) $requisition->id,
                'requisition_no' => (string) $requisition->requisition_no,
                'title' => (string) $requisition->title,
                'status' => (string) $requisition->status,
                'headcount' => (int) $requisition->headcount,
                'case_id' => $requisition->case_id,
            ])->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function storeRequisition(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->allow($principal, 'recruitment.requisition.manage', AnchorRef::company());

        $data = $request->validate([
            'org_unit_id' => ['required', 'integer', 'min:1'],
            'requisition_no' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'],
            'headcount' => ['required', 'integer', 'min:1', 'max:1000'],
            'budget_minor' => ['nullable', 'integer', 'min:0'],
            'budget_currency' => ['nullable', 'string', 'size:3'],
            'target_start_date' => ['nullable', new BusinessDateRule],
        ]);

        return $this->command($request, 'requisition', $data, fn (): array => $this->recruitment->createRequisition($principal->userId(), $data, $today->value()));
    }

    public function candidates(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'recruitment.candidate.read', AnchorRef::company());

        $items = \App\Modules\Talent\Models\Candidate::query()
            ->orderBy('id')
            ->get()
            ->map(static fn (\App\Modules\Talent\Models\Candidate $candidate): array => [
                'candidate_id' => (int) $candidate->id,
                'display_name' => (string) $candidate->display_name,
                'status' => (string) $candidate->status,
                'has_email' => $candidate->email_hash !== null,
            ])->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function storeCandidate(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'recruitment.candidate.manage', AnchorRef::company());

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
        $this->allow($principal, 'recruitment.interview.manage', AnchorRef::company());

        $data = $request->validate([
            'requisition_id' => ['required', 'integer', 'min:1'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'interviewer' => ['required', 'string', 'max:120'],
            'mode' => ['nullable', 'string', 'in:VIDEO,ONSITE,PHONE'],
            'feedback' => ['nullable', 'string', 'max:4000'],
            'recommendation' => ['nullable', 'string', 'in:STRONG_YES,YES,NO,STRONG_NO'],
        ]);

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
     * @param  \Closure(): array<string, mixed>  $operation
     */
    private function command(Request $request, string $scope, array $data, \Closure $operation): JsonResponse
    {
        $principal = $this->principals->resolve();
        $key = (string) $request->header('Idempotency-Key', '');

        if ($key === '') {
            throw new ValidationException(['idempotency_key' => ['A valid Idempotency-Key header is required.']]);
        }

        $result = DB::transaction(fn (): \App\Modules\Platform\Contracts\IdempotentResult => $this->idempotency->run(
            principalId: $principal->userId(),
            scope: 'POST:/v1/recruitment/'.$scope,
            key: $key,
            payload: $data,
            operation: function () use ($operation): array {
                $body = $operation();

                return [
                    'http_status' => 201,
                    'body' => $body,
                    'resource_type' => 'talent',
                    'resource_id' => (string) ($body['candidate_id'] ?? $body['offer_id'] ?? $body['requisition_id'] ?? ''),
                ];
            },
        ));

        /** @var array<string, mixed> $body */
        $body = $result->body;
        $createdStatus = ($body['replayed'] ?? false) === true ? 200 : 201;
        $response = $result->replayed || $createdStatus === 200 ? ApiResponse::ok($body) : ApiResponse::created($body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    private function allow(Principal $principal, string $capability, AnchorRef $anchor): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->allows($principal, $capability, $anchor, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
