<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Frozen read contract consumed by IAM anchor resolvers and other modules.
 * Every method is tenant-scoped and as-of a tenant business date.
 */
interface OrganizationDirectory
{
    /**
     * @param  list<int>  $orgIds
     * @return list<int>
     */
    public function orgUnitIds(int $tenantId, array $orgIds, string $on): array;

    /**
     * @return list<int>
     */
    public function subtreeIds(int $tenantId, int $orgId, string $on): array;

    /**
     * @return list<array{id: int, parent_id: int|null, code: string, name: string, type: string, legal_entity_id: int}>
     */
    public function tree(int $tenantId, int $legalEntityId, string $on): array;

    public function legalEntityOfOrg(int $tenantId, int $orgId, string $on): ?int;
}
