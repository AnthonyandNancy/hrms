<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Supplementary read contract for modules that need legal-entity metadata or a
 * single org-unit fact without depending on Organization models.
 */
interface OrganizationFacts
{
    public function legalEntity(int $tenantId, int $legalEntityId): ?LegalEntityInfo;

    public function orgUnit(int $tenantId, int $orgUnitId): ?OrgUnitInfo;

    public function defaultTimezone(int $tenantId): string;
}
