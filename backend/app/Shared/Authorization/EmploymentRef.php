<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * Minimal employment context returned by the People port for authorization.
 * It carries identifiers only; scope is the single company.
 */
final readonly class EmploymentRef
{
    public function __construct(
        public int $employmentId,
        public int $personId,
        public ?int $userId = null,
        public ?int $orgUnitId = null,
        public ?int $managerEmploymentId = null,
        public ?string $validFrom = null,
        public ?string $validTo = null,
    ) {}
}
