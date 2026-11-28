<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Lifecycle\Domain\LifecycleCaseType;
use App\Modules\Lifecycle\Services\ChecklistService;
use App\Modules\Lifecycle\Services\LifecycleCaseListService;
use App\Modules\Lifecycle\Services\LifecycleProjectionService;
use App\Modules\Lifecycle\Services\LifecycleService;
use App\Modules\People\Contracts\OnboardingDraftStore;
use App\Modules\Platform\Contracts\IdempotencyRunner;
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
use Illuminate\Validation\Rule;

final class LifecycleController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly LifecycleService $lifecycle,
        private readonly LifecycleProjectionService $projection,
        private readonly LifecycleCaseListService $caseList,
        private readonly ChecklistService $checklists,
        private readonly IdempotencyRunner $idempotency,
        private readonly OnboardingDraftStore $onboardingDrafts,
    ) {}

    public function onboarding(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertAllows($principal, 'lifecycle.onboarding.manage', AnchorRef::company(), $today->value());

        $data = $request->validate([
            'person_id' => ['nullable', 'integer', 'min:1'],
            'person' => ['nullable', 'array'],
            'person.legal_name' => ['required_without:person_id', 'string', 'max:120'],
            'person.email' => ['nullable', 'email', 'max:254'],
            'person.mobile' => ['nullable', 'string', 'max:40'],
            'person.id_type' => ['nullable', 'string', 'max:32'],
            'person.id_number' => ['nullable', 'string', 'max:80'],
            'employee_no' => ['required', 'string', 'max:64'],
            'org_unit_id' => ['required', 'integer', 'min:1'],
            'position_id' => ['nullable', 'integer', 'min:1'],
            'manager_assignment_id' => ['nullable', 'integer', 'min:1'],
            'hire_date' => ['required', new BusinessDateRule],
            'employment_type' => ['required', 'string', 'in:FULL_TIME,PART_TIME,FIXED_TERM,INTERN,CONTRACTOR'],
            'terms' => ['required', 'array'],
            'terms.work_location' => ['required', 'string', 'max:160'],
            'terms.work_schedule' => ['required', 'string', 'max:80'],
            'terms.probation_end_date' => ['nullable', new BusinessDateRule],
            'terms.salary_scheme_code' => ['nullable', 'string', 'max:64'],
            'terms.base_salary_minor' => ['nullable', 'integer', 'min:0'],
            'terms.salary_currency' => ['nullable', 'string', 'size:3'],
            'terms.notice_period_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'terms.confidentiality' => ['nullable', 'boolean'],
            'terms.non_compete' => ['nullable', 'boolean'],
            'terms.signed_at' => ['nullable', 'date'],
            'candidate_id' => ['nullable', 'integer', 'min:1'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        $this->assertOnboardingOrgScope($principal, (int) $data['org_unit_id'], $today->value());
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        if ($idempotencyKey === '') {
            throw new ValidationException(['idempotency_key' => ['A valid Idempotency-Key header is required.']]);
        }

        $draftId = null;

        if (isset($data['person']) && is_array($data['person'])) {
            $draft = $this->onboardingDrafts->reserve(
                employeeNo: (string) $data['employee_no'],
                idempotencyKey: $idempotencyKey,
                person: $data['person'],
                actorUserId: $principal->userId(),
            );
            $draftId = (int) $draft['draft_id'];
            $data['onboarding_draft_id'] = $draftId;
            unset($data['person']);
        }

        return $this->command(
            $request,
            'onboarding',
            $principal,
            $data,
            LifecycleCaseType::ONBOARDING,
            $today->value(),
            $draftId,
        );
    }

    public function probationConfirmation(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertAllows($principal, 'lifecycle.probation.manage', AnchorRef::company(), $today->value());

        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'effective_date' => ['required', new BusinessDateRule],
            'comment' => ['nullable', 'string', 'max:1000'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        return $this->command($request, 'probation-confirmations', $principal, $data, LifecycleCaseType::PROBATION_CONFIRM, $today->value());
    }

    public function transfer(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertAllows($principal, 'lifecycle.transfer.manage', AnchorRef::company(), $today->value());

        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'org_unit_id' => ['required', 'integer', 'min:1'],
            'position_id' => ['nullable', 'integer', 'min:1'],
            'manager_assignment_id' => ['nullable', 'integer', 'min:1'],
            'effective_date' => ['required', new BusinessDateRule],
            'reason' => ['required', 'string', 'max:500'],
            'salary_scheme_code' => ['nullable', 'string', 'max:64'],
            'base_salary_minor' => ['nullable', 'integer', 'min:0'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        return $this->command($request, 'transfers', $principal, $data, LifecycleCaseType::ASSIGNMENT_CHANGE, $today->value());
    }

    public function salaryChange(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertAllows($principal, 'lifecycle.terms.manage', AnchorRef::company(), $today->value());

        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'effective_date' => ['required', new BusinessDateRule],
            'base_salary_minor' => ['required', 'integer', 'min:0'],
            'salary_currency' => ['nullable', 'string', 'size:3'],
            'salary_scheme_code' => ['nullable', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:500'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        return $this->command($request, 'salary-changes', $principal, $data, LifecycleCaseType::TERMS_CHANGE, $today->value());
    }

    public function offboarding(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertAllows($principal, 'lifecycle.offboard.manage', AnchorRef::company(), $today->value());

        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'last_working_date' => ['required', new BusinessDateRule],
            'termination_type' => ['required', 'string', 'in:RESIGNATION,TERMINATION,RETIREMENT,CONTRACT_END,MUTUAL_AGREEMENT'],
            'reason_code' => ['required', 'string', 'max:64'],
            'checklist' => ['sometimes', 'array'],
            'checklist.*.task_code' => ['required_with:checklist', 'string', 'max:64'],
            'checklist.*.status' => ['required_with:checklist', 'string', 'in:DONE,WAIVED'],
            'checklist.*.waiver_reason' => ['nullable', 'string', 'max:500'],
            'final_payroll_required' => ['sometimes', 'boolean'],
            'final_payroll_waiver_reason' => ['nullable', 'string', 'max:500'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        return $this->command($request, 'offboardings', $principal, $data, LifecycleCaseType::OFFBOARD, $today->value());
    }

    public function contractRenewal(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertAllows($principal, 'lifecycle.contract.manage', AnchorRef::company(), $today->value());

        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'effective_date' => ['required', new BusinessDateRule],
            'effective_to' => ['nullable', new BusinessDateRule],
            'expires_at' => ['nullable', new BusinessDateRule],
            'file_object_id' => ['nullable', 'integer', 'min:1'],
            'base_salary_minor' => ['nullable', 'integer', 'min:0'],
            'salary_scheme_code' => ['nullable', 'string', 'max:64'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        return $this->command($request, 'contract-renewals', $principal, $data, LifecycleCaseType::CONTRACT_RENEW, $today->value());
    }

    public function rehire(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertAllows($principal, 'lifecycle.rehire.manage', AnchorRef::company(), $today->value());

        $data = $request->validate([
            'person_id' => ['required', 'integer', 'min:1'],
            'previous_employment_id' => ['required', 'integer', 'min:1'],
            'employee_no' => ['required', 'string', 'max:64'],
            'org_unit_id' => ['required', 'integer', 'min:1'],
            'position_id' => ['nullable', 'integer', 'min:1'],
            'manager_assignment_id' => ['nullable', 'integer', 'min:1'],
            'hire_date' => ['required', new BusinessDateRule],
            'employment_type' => ['required', 'string', 'in:FULL_TIME,PART_TIME,FIXED_TERM,INTERN,CONTRACTOR'],
            'seniority_rule' => ['required', 'string', 'in:PRESERVE_ORIGINAL,REHIRE_DATE'],
            'terms' => ['required', 'array'],
            'terms.work_location' => ['required', 'string', 'max:160'],
            'terms.work_schedule' => ['required', 'string', 'max:80'],
            'terms.base_salary_minor' => ['nullable', 'integer', 'min:0'],
            'terms.salary_currency' => ['nullable', 'string', 'size:3'],
            'terms.salary_scheme_code' => ['nullable', 'string', 'max:64'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        return $this->command($request, 'rehires', $principal, $data, LifecycleCaseType::REHIRE, $today->value());
    }

    public function cases(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertAllows($principal, 'lifecycle.case.read', AnchorRef::company(), $today->value());
        $data = $request->validate([
            'case_type' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'string', 'max:64'],
            'kind' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'max:24'],
            'employment_id' => ['nullable', 'integer', 'min:1'],
            'effective_from' => ['nullable', new BusinessDateRule],
            'effective_to' => ['nullable', new BusinessDateRule],
            'as_of' => ['nullable', new BusinessDateRule],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::ok($this->caseList->paginate(
            [
                'case_type' => $data['case_type'] ?? null,
                'type' => $data['type'] ?? null,
                'kind' => $data['kind'] ?? null,
                'status' => $data['status'] ?? null,
                'employment_id' => $data['employment_id'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'q' => $data['q'] ?? null,
            ],
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 20),
            $data['as_of'] ?? null,
            $principal->userId(),
        ));
    }

    public function showCase(Request $request, int $caseId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertAllows($principal, 'lifecycle.case.read', AnchorRef::company(), $today->value());
        $data = $this->projection->detail($caseId, $principal->userId());

        return ApiResponse::ok($data);
    }

    public function onboardingChecklist(Request $request, int $employmentId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertAllows($principal, 'lifecycle.case.read', AnchorRef::company(), $today->value());
        $items = $this->projection->checklistForEmployment($employmentId);

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function updateChecklistTask(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today();
        $this->assertAllows($principal, 'lifecycle.checklist.manage', AnchorRef::company(), $today->value());

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['DONE', 'WAIVED'])],
            'waiver_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $key = (string) $request->header('Idempotency-Key', '');

        if ($key === '') {
            throw new ValidationException(['idempotency_key' => ['A valid Idempotency-Key header is required.']]);
        }

        $result = DB::transaction(fn (): \App\Modules\Platform\Contracts\IdempotentResult => $this->idempotency->run(
            principalId: $principal->userId(),
            scope: "PATCH:/v1/onboarding-tasks/{$id}",
            key: $key,
            payload: $data,
            operation: function () use ($principal, $id, $data): array {
                $updated = $this->checklists->update(
                    $id,
                    $principal->userId(),
                    (string) $data['status'],
                    $data['waiver_reason'] ?? null,
                );

                return ['http_status' => 200, 'body' => $updated, 'resource_type' => 'lifecycle_task', 'resource_id' => (string) $id];
            },
        ));

        return ApiResponse::ok($result->body);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function command(
        Request $request,
        string $path,
        Principal $principal,
        array $data,
        LifecycleCaseType $type,
        string $businessDate,
        ?int $onboardingDraftId = null,
    ): JsonResponse {
        $key = (string) $request->header('Idempotency-Key', '');

        if ($key === '') {
            throw new ValidationException(['idempotency_key' => ['A valid Idempotency-Key header is required.']]);
        }

        $result = DB::transaction(fn (): \App\Modules\Platform\Contracts\IdempotentResult => $this->idempotency->run(
            principalId: $principal->userId(),
            scope: $type === LifecycleCaseType::ONBOARDING
                ? 'CMD:lifecycle.onboarding.create'
                : "POST:/v1/lifecycle/{$path}",
            key: $key,
            payload: $data,
            operation: function () use ($principal, $data, $type, $businessDate, $onboardingDraftId): array {
                $created = $this->lifecycle->createCase(
                    requesterUserId: $principal->userId(),
                    type: $type,
                    payload: $data,
                    businessDate: $businessDate,
                    submit: (bool) ($data['submit'] ?? true),
                );

                $case = $created['case'];

                if ($onboardingDraftId !== null) {
                    $this->onboardingDrafts->attachCase($onboardingDraftId, (int) $case->id);
                }

                return [
                    'http_status' => 201,
                    'body' => [
                        'case_id' => $case->id,
                        'case_no' => $case->caseNo,
                        'case_type' => $case->type,
                        'application_status' => $case->applicationStatus,
                        'effect_status' => $case->effectStatus,
                        'onboarding_draft_id' => $onboardingDraftId,
                        'draft_employment_intent' => [
                            'person_id' => $data['person_id'] ?? null,
                            'employee_no' => $data['employee_no'] ?? null,
                            'hire_date' => $data['hire_date'] ?? null,
                        ],
                    ],
                    'resource_type' => 'case',
                    'resource_id' => (string) $case->id,
                ];
            },
        ));

        $response = $result->replayed ? ApiResponse::ok($result->body) : ApiResponse::created($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    private function assertAllows(Principal $principal, string $capability, AnchorRef $anchor, string $on): void
    {
        if (! $this->authorizer->allows($principal, $capability, $anchor, $on)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    private function assertOnboardingOrgScope(Principal $principal, int $orgUnitId, string $on): void
    {
        $company = $this->authorizer->allows($principal, 'lifecycle.onboarding.manage', AnchorRef::company(), $on);
        $orgUnit = $this->authorizer->allows($principal, 'lifecycle.onboarding.manage', AnchorRef::orgUnit($orgUnitId), $on);
        $subtree = $this->authorizer->allows($principal, 'lifecycle.onboarding.manage', AnchorRef::orgSubtree($orgUnitId), $on);

        if (! $company && ! $orgUnit && ! $subtree) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
