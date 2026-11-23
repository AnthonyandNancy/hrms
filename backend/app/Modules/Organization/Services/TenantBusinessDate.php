<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use App\Shared\Time\TenantBusinessDate as SharedTenantBusinessDate;

/**
 * Backwards-compatible Organization-local wrapper around the shared resolver.
 */
final class TenantBusinessDate
{
    private SharedTenantBusinessDate $inner;

    public function __construct(Clock $clock, OrganizationFacts $organization)
    {
        $this->inner = new SharedTenantBusinessDate($clock, $organization);
    }

    public function today(int $tenantId): BusinessDate
    {
        return $this->inner->today($tenantId);
    }

    public function resolve(?string $value, int $tenantId, string $field = 'as_of'): BusinessDate
    {
        return $this->inner->resolve($value, $tenantId, $field);
    }
}
