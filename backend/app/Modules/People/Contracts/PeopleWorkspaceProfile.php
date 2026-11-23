<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Self-service profile projection for the caller's own employment. IAM
 * composes this port for /v1/me/workspace; People remains the single owner of
 * Person, Employment, Assignment and Terms facts.
 */
final readonly class PeopleWorkspaceProfile
{
    public function __construct(
        public int $employmentId,
        public int $legalEntityId,
        public string $personName,
        public string $employeeNo,
        public string $initials,
        public ?string $jobTitle,
        public ?string $orgName,
        public ?string $managerName,
        public ?string $workLocation,
        public ?string $email,
        public string $employmentType,
        public string $employmentStatus,
        public string $validFrom,
        public ?string $termsSignedAt,
        public ?int $termsVersion = null,
        public ?string $termsEffectiveFrom = null,
        public ?string $termsEffectiveTo = null,
        public ?string $workSchedule = null,
    ) {}
}
