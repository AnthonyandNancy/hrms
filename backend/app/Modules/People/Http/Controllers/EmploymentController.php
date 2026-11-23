<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Services\AssignmentService;
use App\Modules\People\Services\DocumentService;
use App\Modules\People\Services\EmploymentService;
use App\Modules\People\Services\TermsService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Time\TenantBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmploymentController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TenantBusinessDate $businessDates,
    ) {}

    public function store(Request $request, EmploymentService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today($principal->tenantId());
        $this->assertAllows($principal, 'people.employee.manage', $today->value());

        $data = $request->validate([
            'person_id' => ['required', 'integer', 'min:1'],
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'employee_no' => ['required', 'string', 'max:64'],
            'employment_type' => ['required', 'string', 'in:FULL_TIME,PART_TIME,FIXED_TERM,INTERN,CONTRACTOR'],
            'status' => ['nullable', 'string', 'in:PROBATION,ACTIVE,LEAVE,SUSPENDED,TERMINATED'],
            'valid_from' => ['required', new BusinessDateRule],
            'valid_to' => ['nullable', new BusinessDateRule],
            'hire_date' => ['required', new BusinessDateRule],
            'termination_date' => ['nullable', new BusinessDateRule],
            'seniority_start_date' => ['nullable', new BusinessDateRule],
        ]);

        $employment = $service->open($principal->tenantId(), $data, $principal->userId());

        return ApiResponse::created($this->employmentPayload($employment));
    }

    public function storeAssignment(Request $request, int $employmentId, AssignmentService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today($principal->tenantId());
        $this->assertAllows($principal, 'people.assignment.manage', $today->value());

        $data = $request->validate([
            'org_unit_id' => ['required', 'integer', 'min:1'],
            'position_id' => ['nullable', 'integer', 'min:1'],
            'manager_assignment_id' => ['nullable', 'integer', 'min:1'],
            'assignment_type' => ['required', 'string', 'in:PRIMARY,SECONDMENT,MATRIX,PROJECT'],
            'is_primary' => ['nullable', 'boolean'],
            'valid_from' => ['required', new BusinessDateRule],
            'valid_to' => ['nullable', new BusinessDateRule],
        ]);

        $assignment = $service->open($principal->tenantId(), $employmentId, $data, $principal->userId());

        return ApiResponse::created($this->assignmentPayload($assignment));
    }

    public function storeTerms(Request $request, int $employmentId, TermsService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today($principal->tenantId());
        $this->assertAllows($principal, 'people.contract.manage', $today->value());

        $data = $request->validate([
            'effective_from' => ['required', new BusinessDateRule],
            'effective_to' => ['nullable', new BusinessDateRule],
            'probation_end_date' => ['nullable', new BusinessDateRule],
            'work_location' => ['required', 'string', 'max:160'],
            'work_schedule' => ['required', 'string', 'max:80'],
            'salary_scheme_code' => ['nullable', 'string', 'max:64'],
            'base_salary_cents' => ['nullable', 'integer', 'min:0'],
            'salary_currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'notice_period_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'confidentiality' => ['nullable', 'boolean'],
            'non_compete' => ['nullable', 'boolean'],
            'signed_at' => ['nullable', 'date'],
        ]);

        $terms = $service->append($principal->tenantId(), $employmentId, $data, $principal->userId());

        return ApiResponse::created($this->termsPayload($terms));
    }

    public function storeDocument(Request $request, int $employmentId, DocumentService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today($principal->tenantId());
        $this->assertAllows($principal, 'people.contract.manage', $today->value());

        $data = $request->validate([
            'file_object_id' => ['required', 'integer', 'min:1'],
            'category' => ['required', 'string', 'in:CONTRACT,ID,EDUCATION,CERTIFICATE,OTHER'],
            'signed_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', new BusinessDateRule],
            'retention_class' => ['required', 'string', 'max:32'],
            'legal_hold' => ['nullable', 'boolean'],
        ]);

        $document = $service->open($principal->tenantId(), $employmentId, $data, $principal->userId());

        return ApiResponse::created([
            'id' => (int) $document->id,
            'owner_type' => (string) $document->owner_type,
            'owner_id' => (int) $document->owner_id,
            'category' => (string) $document->category,
            'version' => (int) $document->version,
            'signed_at' => $document->signed_at !== null ? \Carbon\CarbonImmutable::parse((string) $document->signed_at)->toIso8601ZuluString() : null,
            'expires_at' => $document->expires_at !== null ? (string) $document->expires_at : null,
            'retention_class' => (string) $document->retention_class,
            'legal_hold' => (bool) $document->legal_hold,
        ]);
    }

    public function downloadDocument(int $documentId, DocumentService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today($principal->tenantId());
        $this->assertAllows($principal, 'people.contract.read', $today->value());
        $this->assertAllows($principal, 'people.document.download', $today->value());

        $document = $service->findForDownload($principal->tenantId(), $documentId);

        if ($document === null) {
            throw new NotFoundOrForbiddenException;
        }

        return ApiResponse::ok([
            'document_id' => (int) $document->id,
            'download_requires_signed_url' => true,
            'storage_key' => null,
        ]);
    }

    public function transfer(Request $request, int $employmentId, EmploymentService $service): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today($principal->tenantId());
        $this->assertAllows($principal, 'people.employee.manage', $today->value());

        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', new BusinessDateRule],
            'employee_no' => ['nullable', 'string', 'max:64'],
            'employment_type' => ['nullable', 'string', 'in:FULL_TIME,PART_TIME,FIXED_TERM,INTERN,CONTRACTOR'],
        ]);

        $employment = $service->transferLegalEntity($principal->tenantId(), $employmentId, $data, $principal->userId());

        return ApiResponse::created($this->employmentPayload($employment));
    }

    private function assertAllows(Principal $principal, string $capability, string $on): void
    {
        if (! $this->authorizer->allows($principal, $capability, AnchorRef::self(), $on)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function employmentPayload(Employment $employment): array
    {
        return [
            'employment_id' => (int) $employment->id,
            'person_id' => (int) $employment->person_id,
            'legal_entity_id' => (int) $employment->legal_entity_id,
            'employee_no' => (string) $employment->employee_no,
            'employment_type' => (string) $employment->employment_type,
            'status' => (string) $employment->status,
            'valid_from' => (string) $employment->valid_from,
            'valid_to' => (string) $employment->valid_to,
            'hire_date' => (string) $employment->hire_date,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentPayload(Assignment $assignment): array
    {
        return [
            'id' => (int) $assignment->id,
            'employment_id' => (int) $assignment->employment_id,
            'org_unit_id' => (int) $assignment->org_unit_id,
            'position_id' => $assignment->position_id !== null ? (int) $assignment->position_id : null,
            'manager_assignment_id' => $assignment->manager_assignment_id !== null ? (int) $assignment->manager_assignment_id : null,
            'assignment_type' => (string) $assignment->assignment_type,
            'is_primary' => (bool) $assignment->is_primary,
            'valid_from' => (string) $assignment->valid_from,
            'valid_to' => (string) $assignment->valid_to,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function termsPayload(EmploymentTerms $terms): array
    {
        return [
            'id' => (int) $terms->id,
            'employment_id' => (int) $terms->employment_id,
            'version' => (int) $terms->version,
            'effective_from' => (string) $terms->effective_from,
            'effective_to' => (string) $terms->effective_to,
            'work_location' => (string) $terms->work_location,
            'work_schedule' => (string) $terms->work_schedule,
            'salary_scheme_code' => $terms->salary_scheme_code !== null ? (string) $terms->salary_scheme_code : null,
            'notice_period_days' => (int) $terms->notice_period_days,
            'confidentiality' => (bool) $terms->confidentiality,
            'non_compete' => (bool) $terms->non_compete,
        ];
    }
}
