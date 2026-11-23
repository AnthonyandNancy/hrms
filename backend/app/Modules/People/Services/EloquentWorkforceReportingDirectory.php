<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Contracts\WorkforceReportingDirectory;
use App\Modules\People\Contracts\WorkforceReportingRow;
use App\Modules\People\Models\Document;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\Person;
use App\Shared\Time\AsOfQuery;

final class EloquentWorkforceReportingDirectory implements WorkforceReportingDirectory
{
    public function __construct(private readonly OrganizationFacts $organization) {}

    /**
     * @return list<WorkforceReportingRow>
     */
    public function rows(int $tenantId, string $on): array
    {
        $employments = Employment::query()
            ->where('tenant_id', $tenantId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $on))
            ->orderBy('id')
            ->get();
        $rows = [];

        foreach ($employments as $employment) {
            /** @var Employment $employment */
            $person = Person::query()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $employment->person_id)
                ->first();
            $terms = EmploymentTerms::query()
                ->where('tenant_id', $tenantId)
                ->where('employment_id', (int) $employment->id)
                ->where('effective_from', '<=', $on)
                ->where('effective_to', '>', $on)
                ->orderByDesc('version')
                ->first();
            $assignment = \App\Modules\People\Models\Assignment::query()
                ->where('tenant_id', $tenantId)
                ->where('employment_id', (int) $employment->id)
                ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $on))
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->first();
            $orgUnitId = $assignment?->org_unit_id !== null ? (int) $assignment->org_unit_id : null;
            $org = $orgUnitId !== null ? $this->organization->orgUnit($tenantId, $orgUnitId) : null;
            $legalEntity = $this->organization->legalEntity($tenantId, (int) $employment->legal_entity_id);
            $contract = Document::query()
                ->where('tenant_id', $tenantId)
                ->where('owner_type', 'EMPLOYMENT')
                ->where('owner_id', (int) $employment->id)
                ->where('category', 'CONTRACT')
                ->whereNotNull('expires_at')
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->first();

            $rows[] = new WorkforceReportingRow(
                employmentId: (int) $employment->id,
                personId: (int) $employment->person_id,
                legalEntityId: (int) $employment->legal_entity_id,
                orgUnitId: $orgUnitId,
                employeeNo: (string) $employment->employee_no,
                displayName: $person?->legal_name !== null ? (string) $person->legal_name : null,
                status: (string) $employment->status,
                probationEndDate: $terms?->probation_end_date !== null ? (string) $terms->probation_end_date : null,
                contractEndDate: $contract?->getAttribute('expires_at') !== null ? (string) $contract->getAttribute('expires_at') : null,
                orgName: $org?->name,
                legalEntityName: $legalEntity?->name,
            );
        }

        return $rows;
    }
}
