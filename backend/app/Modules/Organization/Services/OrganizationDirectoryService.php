<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use App\Shared\Time\AsOfQuery;
use App\Shared\Time\BusinessDate;

final class OrganizationDirectoryService implements OrganizationDirectory
{
    /**
     * @param  list<int>  $orgIds
     * @return list<int>
     */
    public function orgUnitIds(int $tenantId, array $orgIds, string $on): array
    {
        $date = BusinessDate::from($on)->value();

        return OrgUnit::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $orgIds)
            ->where('status', 'ACTIVE')
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    public function subtreeIds(int $tenantId, int $orgId, string $on): array
    {
        $date = BusinessDate::from($on)->value();

        return OrgUnitClosure::query()
            ->where('tenant_id', $tenantId)
            ->where('ancestor_id', $orgId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
            ->orderBy('descendant_id')
            ->pluck('descendant_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The org_units row is the stable identity. A historical tree is rebuilt
     * from the versioned closure (and the effective org rows) so an as-of tree
     * does not leak a parent relation learned after the requested date.
     *
     * @return list<array{id: int, parent_id: int|null, code: string, name: string, type: string, legal_entity_id: int}>
     */
    public function tree(int $tenantId, int $legalEntityId, string $on): array
    {
        $date = BusinessDate::from($on)->value();

        $units = OrgUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('status', 'ACTIVE')
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
            ->orderBy('id')
            ->get();

        $parentByUnit = [];

        foreach ($units as $unit) {
            $parentByUnit[(int) $unit->id] = null;
        }

        $parentLinks = OrgUnitClosure::query()
            ->where('tenant_id', $tenantId)
            ->where('depth', 1)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
            ->get();

        foreach ($parentLinks as $link) {
            if (array_key_exists((int) $link->descendant_id, $parentByUnit)) {
                $parentByUnit[(int) $link->descendant_id] = (int) $link->ancestor_id;
            }
        }

        return $units
            ->map(static fn (OrgUnit $unit): array => [
                'id' => (int) $unit->id,
                'parent_id' => $parentByUnit[(int) $unit->id] ?? null,
                'code' => (string) $unit->code,
                'name' => (string) $unit->name,
                'type' => (string) $unit->type,
                'legal_entity_id' => (int) $unit->legal_entity_id,
            ])
            ->all();
    }

    public function legalEntityOfOrg(int $tenantId, int $orgId, string $on): ?int
    {
        $date = BusinessDate::from($on)->value();

        $entityId = OrgUnit::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($orgId)
            ->where('status', 'ACTIVE')
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
            ->value('legal_entity_id');

        return $entityId !== null ? (int) $entityId : null;
    }
}
