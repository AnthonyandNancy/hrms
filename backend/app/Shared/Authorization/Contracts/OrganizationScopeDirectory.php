<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

/**
 * Read-only Organization port for authorization. The People module will bind
 * a real adapter; IAM only needs stable IDs and as-of hierarchy membership.
 */
interface OrganizationScopeDirectory
{
    /**
     * Whether an organization unit exists in the tenant and is effective on
     * the business date.
     */
    public function isUnitInTenant(int $tenantId, int $orgUnitId, string $businessDate): bool;

    /**
     * The legal entity that owns an organization unit on the business date.
     */
    public function legalEntityForUnit(int $tenantId, int $orgUnitId, string $businessDate): ?int;

    /**
     * All unit IDs at or below the given unit on the business date.
     *
     * @return list<int>
     */
    public function descendantUnitIds(int $tenantId, int $orgUnitId, string $businessDate): array;

    /**
     * Organization units under a legal entity on the business date.
     *
     * @return list<int>
     */
    public function unitIdsForLegalEntity(int $tenantId, int $legalEntityId, string $businessDate): array;

    /**
     * Whether the legal entity exists in the tenant.
     */
    public function legalEntityExists(int $tenantId, int $legalEntityId): bool;
}
