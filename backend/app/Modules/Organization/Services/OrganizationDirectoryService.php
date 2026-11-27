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
    public function orgUnitIds(array $orgIds, string $on): array
    {
        $date = BusinessDate::from($on)->value();

        return OrgUnit::query()
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
    public function subtreeIds(int $orgId, string $on): array
    {
        $date = BusinessDate::from($on)->value();

        return OrgUnitClosure::query()
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
     * @return list<array{id: int, parent_id: int|null, code: string, name: string, type: string}>
     */
    public function tree(string $on): array
    {
        $date = BusinessDate::from($on)->value();

        $units = OrgUnit::query()
            ->where('status', 'ACTIVE')
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $date))
            ->orderBy('id')
            ->get();

        $parentByUnit = [];

        foreach ($units as $unit) {
            $parentByUnit[(int) $unit->id] = null;
        }

        $parentLinks = OrgUnitClosure::query()
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
            ])
            ->all();
    }
}
