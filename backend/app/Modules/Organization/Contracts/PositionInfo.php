<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Tenant-scoped position projection. People and Payroll resolve position
 * titles through this port instead of importing Organization models.
 */
final readonly class PositionInfo
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $legalEntityId,
        public ?int $orgUnitId,
        public string $code,
        public string $title,
        public ?string $grade,
    ) {}
}
