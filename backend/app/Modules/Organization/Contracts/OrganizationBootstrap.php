<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Organization-owned reference-data bootstrap used by the installer. Other
 * modules must not write Organization tables directly; the installer calls this
 * port so the owning module controls entity and closure creation.
 */
interface OrganizationBootstrap
{
    /**
     * @return array{company_profile_id: int, company_profile_created: bool, root_org_unit_id: int, root_org_unit_created: bool}
     */
    public function ensureCompanyWithRoot(
        string $code,
        string $name,
        string $countryCode,
        string $timezone,
        string $currencyCode,
        string $validFrom = '2026-01-01',
    ): array;
}
