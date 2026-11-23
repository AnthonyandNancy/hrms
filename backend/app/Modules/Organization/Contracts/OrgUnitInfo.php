<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Tenant-scoped organization-unit projection. valid_from/valid_to are the
 * half-open organization identity window.
 */
final readonly class OrgUnitInfo
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $legalEntityId,
        public ?int $parentId,
        public string $code,
        public string $name,
        public string $type,
        public string $status,
        public string $validFrom,
        public string $validTo,
    ) {}
}
