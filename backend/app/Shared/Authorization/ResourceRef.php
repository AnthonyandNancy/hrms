<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * Reference to a resource row for field projection. The resource type prevents
 * callers from accidentally applying one resource's field schema to another.
 */
final class ResourceRef
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function __construct(
        public readonly string $resourceType,
        public readonly int $id,
        public readonly array $row,
        public readonly ?int $tenantId = null,
        public readonly ?int $employmentId = null,
        public readonly ?int $orgUnitId = null,
        public readonly ?int $legalEntityId = null,
        public readonly ?int $caseId = null,
        public readonly ?int $payrollRunId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function row(): array
    {
        return $this->row;
    }
}
