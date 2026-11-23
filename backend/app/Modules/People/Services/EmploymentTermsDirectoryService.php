<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\EmploymentTermsDirectory;
use App\Modules\People\Contracts\EmploymentTermsSnapshot;
use App\Modules\People\Models\EmploymentTerms;
use App\Shared\Time\BusinessDate;

final class EmploymentTermsDirectoryService implements EmploymentTermsDirectory
{
    public function termsAsOf(int $tenantId, int $employmentId, string $on): ?EmploymentTermsSnapshot
    {
        $date = BusinessDate::from($on)->value();

        $terms = EmploymentTerms::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('effective_from', '<=', $date)
            ->where('effective_to', '>', $date)
            ->orderByDesc('version')
            ->first();

        if ($terms === null) {
            return null;
        }

        return new EmploymentTermsSnapshot(
            termsId: (int) $terms->id,
            employmentId: (int) $terms->employment_id,
            version: (int) $terms->version,
            effectiveFrom: (string) $terms->effective_from,
            effectiveTo: (string) $terms->effective_to,
            baseSalaryCents: $terms->base_salary_cents !== null ? (int) $terms->base_salary_cents : null,
            salaryCurrency: $terms->salary_currency !== null ? (string) $terms->salary_currency : null,
            salarySchemeCode: $terms->salary_scheme_code !== null ? (string) $terms->salary_scheme_code : null,
            workLocation: (string) $terms->work_location,
            workSchedule: (string) $terms->work_schedule,
        );
    }
}
