<?php

declare(strict_types=1);

namespace App\Modules\Organization\Authorization;

use App\Modules\Organization\Models\LegalEntity;
use App\Modules\Organization\Models\OrgUnit;
use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;
use App\Shared\Time\AsOfQuery;

/**
 * Production Organization scope adapter for IAM. IAM depends on the Shared
 * contract; Organization owns the tables and as-of closure semantics.
 */
final class EloquentOrganizationScopeDirectory implements OrganizationScopeDirectory
{
    public function isUnitInTenant(int $tenantId, int $orgUnitId, string $businessDate): bool
    {
        return OrgUnit::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($orgUnitId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->exists();
    }

    public function legalEntityForUnit(int $tenantId, int $orgUnitId, string $businessDate): ?int
    {
        $legalEntityId = OrgUnit::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($orgUnitId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->value('legal_entity_id');

        return $legalEntityId === null ? null : (int) $legalEntityId;
    }

    /** @return list<int> */
    public function descendantUnitIds(int $tenantId, int $orgUnitId, string $businessDate): array
    {
        $visited = [];
        $queue = [$orgUnitId];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            $children = OrgUnit::query()
                ->where('tenant_id', $tenantId)
                ->where('parent_id', $current)
                ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            foreach ($children as $child) {
                $queue[] = $child;
            }
        }

        return array_keys($visited);
    }

    /** @return list<int> */
    public function unitIdsForLegalEntity(int $tenantId, int $legalEntityId, string $businessDate): array
    {
        return OrgUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $businessDate))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function legalEntityExists(int $tenantId, int $legalEntityId): bool
    {
        return LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($legalEntityId)
            ->exists();
    }
}
