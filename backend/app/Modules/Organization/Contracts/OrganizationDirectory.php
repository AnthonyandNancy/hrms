<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Frozen read contract consumed by IAM anchor resolvers and other modules.
 * Every method is as-of a company business date.
 */
interface OrganizationDirectory
{
    /**
     * @param  list<int>  $orgIds
     * @return list<int>
     */
    public function orgUnitIds(array $orgIds, string $on): array;

    /**
     * @return list<int>
     */
    public function subtreeIds(int $orgId, string $on): array;

    /**
     * @return list<array{id: int, parent_id: int|null, code: string, name: string, type: string}>
     */
    public function tree(string $on): array;
}
