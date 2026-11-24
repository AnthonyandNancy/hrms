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

    /**
     * @return list<LegalEntityInfo>
     */
    public function legalEntitiesForTenant(int $tenantId): array;

    public function orgUnit(int $tenantId, int $orgUnitId): ?OrgUnitInfo;

    public function position(int $tenantId, int $positionId): ?PositionInfo;

    /**
     * Default calendar timezone for the tenant, falling back to config when a
     * tenant has no legal entity yet.
     */
    public function defaultTimezone(int $tenantId): string;
}
