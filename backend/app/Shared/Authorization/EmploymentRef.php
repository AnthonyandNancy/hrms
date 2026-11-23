<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * Minimal, tenant-safe employment context returned by the People port.
 */
final class EmploymentRef
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $employmentId,
        public readonly ?int $orgUnitId,
        public readonly ?int $legalEntityId,
    ) {}
}
