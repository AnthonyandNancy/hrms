<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Contracts\PeopleWorkspaceDirectory;
use App\Modules\People\Contracts\PeopleWorkspaceProfile;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\People\Models\Person;
use App\Shared\Time\AsOfQuery;
use App\Shared\Time\BusinessDate;

/**
 * Read model for /v1/me/workspace. It is People-owned, as-of a
 * business date and composes only the frozen OrganizationFacts port.
 */
final class EloquentPeopleWorkspaceDirectory implements PeopleWorkspaceDirectory
{
    public function __construct(
        private readonly PeopleDirectory $people,
        private readonly OrganizationFacts $organization,
    ) {}

    public function profile(int $employmentId, string $on): ?PeopleWorkspaceProfile
    {
        $snapshot = $this->people->employmentSnapshot($employmentId, $on);

        if ($snapshot === null) {
            return null;
        }

        $person = Person::query()
            ->whereKey($snapshot->personId)
            ->first();

        $assignment = $snapshot->assignmentId !== null
            ? \App\Modules\People\Models\Assignment::query()
                ->whereKey($snapshot->assignmentId)
                ->first()
            : null;

        $position = $assignment?->position_id !== null
            ? $this->organization->position((int) $assignment->position_id)
            : null;

        $orgUnit = $snapshot->orgUnitId !== null
            ? $this->organization->orgUnit($snapshot->orgUnitId)
            : null;

        $managerName = null;

        if ($snapshot->managerEmploymentId !== null) {
            $managerSnapshot = $this->people->employmentSnapshot($snapshot->managerEmploymentId, $on);
            $managerName = $managerSnapshot !== null
                ? Person::query()
                    ->whereKey($managerSnapshot->personId)
                    ->value('legal_name')
                : null;
        }

        $terms = EmploymentTerms::query()
            ->where('employment_id', $employmentId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, BusinessDate::from($on)->value(), 'effective_from', 'effective_to'))
            ->orderByDesc('version')
            ->first();

        $displayName = $person?->preferred_name !== null && (string) $person->preferred_name !== ''
            ? (string) $person->preferred_name
            : ($person !== null ? (string) $person->legal_name : $snapshot->employeeNo);

        return new PeopleWorkspaceProfile(
            employmentId: $snapshot->employmentId,
            personName: $displayName,
            employeeNo: $snapshot->employeeNo,
            initials: $this->initials($displayName),
            jobTitle: $position?->title,
            orgName: $orgUnit?->name,
            managerName: $managerName !== null ? (string) $managerName : null,
            workLocation: $terms?->work_location !== null ? (string) $terms->work_location : null,
            email: $person?->email !== null ? (string) $person->email : null,
            employmentType: $snapshot->employmentType ?? 'FULL_TIME',
            employmentStatus: $snapshot->status,
            validFrom: $snapshot->validFrom ?? '',
            termsSignedAt: $terms?->signed_at !== null
                ? \Carbon\CarbonImmutable::parse((string) $terms->signed_at)->toIso8601ZuluString()
                : null,
            termsVersion: $terms !== null ? (int) $terms->version : null,
            termsEffectiveFrom: $terms !== null ? (string) $terms->effective_from : null,
            termsEffectiveTo: $terms !== null ? (string) $terms->effective_to : null,
            workSchedule: $terms !== null ? (string) $terms->work_schedule : null,
        );
    }

    private function initials(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '—';
        }

        return mb_strtoupper(mb_substr($trimmed, 0, 2));
    }
}
