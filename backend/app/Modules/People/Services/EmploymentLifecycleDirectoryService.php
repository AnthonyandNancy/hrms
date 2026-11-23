<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\EmploymentAssignmentSnapshot;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Contracts\EmploymentLifecycleSnapshot;
use App\Modules\People\Contracts\EmploymentTermsSnapshot;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\EmploymentUserLink;

final class EmploymentLifecycleDirectoryService implements EmploymentLifecycleDirectory
{
    public function employmentsForPerson(int $tenantId, int $personId): array
    {
        return Employment::query()
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get()
            ->map(fn (Employment $employment): EmploymentLifecycleSnapshot => $this->employmentSnapshot($employment))
            ->all();
    }

    public function employment(int $tenantId, int $employmentId, ?string $knownAt = null): ?EmploymentLifecycleSnapshot
    {
        $query = Employment::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($employmentId);

        if ($knownAt !== null) {
            $query->where('recorded_at', '<=', $knownAt);
        }

        $employment = $query->first();

        return $employment !== null ? $this->employmentSnapshot($employment) : null;
    }

    public function hasOverlappingEmployment(int $tenantId, int $personId, int $legalEntityId, string $validFrom, string $validTo): bool
    {
        return Employment::query()
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('valid_from', '<', $validTo)
            ->where('valid_to', '>', $validFrom)
            ->where('status', '!=', 'TERMINATED')
            ->exists();
    }

    public function employeeNumberExists(int $tenantId, int $legalEntityId, string $employeeNo, ?int $exceptEmploymentId = null): bool
    {
        return Employment::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('employee_no', $employeeNo)
            ->when($exceptEmploymentId !== null, fn ($query) => $query->whereKeyNot($exceptEmploymentId))
            ->exists();
    }

    public function activeManagerAssignment(int $tenantId, int $managerAssignmentId, string $on): bool
    {
        return Assignment::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($managerAssignmentId)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->exists();
    }

    public function primaryAssignmentId(int $tenantId, int $employmentId, string $on): ?int
    {
        $id = Assignment::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('is_primary', true)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderByDesc('valid_from')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function isAssignmentActive(int $tenantId, int $assignmentId, string $on): bool
    {
        return Assignment::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($assignmentId)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->exists();
    }

    public function termsVersions(int $tenantId, int $employmentId): array
    {
        return EmploymentTerms::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->orderBy('version')
            ->get()
            ->map(fn (EmploymentTerms $terms): EmploymentTermsSnapshot => $this->termsSnapshot($terms))
            ->all();
    }

    public function termsAsOf(int $tenantId, int $employmentId, string $on): ?EmploymentTermsSnapshot
    {
        $terms = EmploymentTerms::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('effective_from', '<=', $on)
            ->where('effective_to', '>', $on)
            ->orderByDesc('version')
            ->first();

        return $terms !== null ? $this->termsSnapshot($terms) : null;
    }

    public function assignments(int $tenantId, int $employmentId): array
    {
        return Assignment::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get()
            ->map(fn (Assignment $assignment): EmploymentAssignmentSnapshot => $this->assignmentSnapshot($assignment))
            ->all();
    }

    public function assignmentAsOf(int $tenantId, int $employmentId, string $on): ?EmploymentAssignmentSnapshot
    {
        $assignment = Assignment::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        return $assignment !== null ? $this->assignmentSnapshot($assignment) : null;
    }

    public function linkedUserIds(int $tenantId, int $employmentId, string $on): array
    {
        return EmploymentUserLink::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function employmentSnapshot(Employment $employment): EmploymentLifecycleSnapshot
    {
        return new EmploymentLifecycleSnapshot(
            id: (int) $employment->id,
            tenantId: (int) $employment->tenant_id,
            personId: (int) $employment->person_id,
            legalEntityId: (int) $employment->legal_entity_id,
            employeeNo: (string) $employment->employee_no,
            employmentType: (string) $employment->employment_type,
            status: (string) $employment->status,
            validFrom: (string) $employment->valid_from,
            validTo: (string) $employment->valid_to,
            hireDate: (string) $employment->hire_date,
            terminationDate: $employment->termination_date !== null ? (string) $employment->termination_date : null,
            seniorityStartDate: (string) $employment->seniority_start_date,
        );
    }

    private function termsSnapshot(EmploymentTerms $terms): EmploymentTermsSnapshot
    {
        return new EmploymentTermsSnapshot(
            id: (int) $terms->id,
            employmentId: (int) $terms->employment_id,
            version: (int) $terms->version,
            effectiveFrom: (string) $terms->effective_from,
            effectiveTo: (string) $terms->effective_to,
            probationEndDate: $terms->probation_end_date !== null ? (string) $terms->probation_end_date : null,
            workLocation: (string) $terms->work_location,
            workSchedule: (string) $terms->work_schedule,
            salarySchemeCode: $terms->salary_scheme_code !== null ? (string) $terms->salary_scheme_code : null,
            baseSalaryMinor: $terms->base_salary_minor !== null ? (int) $terms->base_salary_minor : null,
            salaryCurrency: (string) $terms->salary_currency,
            noticePeriodDays: (int) $terms->notice_period_days,
            confidentiality: (bool) $terms->confidentiality,
            nonCompete: (bool) $terms->non_compete,
            signedAt: $terms->signed_at !== null ? \Carbon\CarbonImmutable::parse((string) $terms->signed_at)->toIso8601ZuluString() : null,
        );
    }

    private function assignmentSnapshot(Assignment $assignment): EmploymentAssignmentSnapshot
    {
        return new EmploymentAssignmentSnapshot(
            id: (int) $assignment->id,
            employmentId: (int) $assignment->employment_id,
            orgUnitId: (int) $assignment->org_unit_id,
            positionId: $assignment->position_id !== null ? (int) $assignment->position_id : null,
            managerAssignmentId: $assignment->manager_assignment_id !== null ? (int) $assignment->manager_assignment_id : null,
            assignmentType: (string) $assignment->assignment_type,
            isPrimary: (bool) $assignment->is_primary,
            validFrom: (string) $assignment->valid_from,
            validTo: (string) $assignment->valid_to,
        );
    }
}
