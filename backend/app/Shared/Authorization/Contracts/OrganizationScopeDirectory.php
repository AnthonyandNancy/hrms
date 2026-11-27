<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

/**
 * Read-only Organization port for authorization. IAM needs stable IDs and
 * as-of hierarchy membership; it never reads Organization tables.
 */
interface OrganizationScopeDirectory
{
    /**
     * Whether an organization unit exists.
     */
    public function orgUnitExists(int $orgUnitId): bool;

    /**
     * Unit IDs from the given unit up to its root.
     *
     * @return list<int>
     */
    public function orgUnitAncestors(int $orgUnitId): array;

    /**
     * All unit IDs at or below the given unit.
     *
     * @return list<int>
     */
    public function descendantOrgUnitIds(int $orgUnitId): array;
}
