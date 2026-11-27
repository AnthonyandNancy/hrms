<?php

declare(strict_types=1);

namespace App\Modules\Organization\Authorization;

use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;
use App\Shared\Time\AsOfQuery;
use App\Shared\Time\CompanyBusinessDate;

/**
 * Production Organization scope adapter for IAM. IAM depends on the Shared
 * contract; Organization owns the tables and as-of closure semantics.
 */
final class EloquentOrganizationScopeDirectory implements OrganizationScopeDirectory
{
    public function __construct(private readonly CompanyBusinessDate $businessDates) {}

    public function orgUnitExists(int $orgUnitId): bool
    {
        $on = $this->businessDates->today()->value();

        return OrgUnit::query()
            ->whereKey($orgUnitId)
            ->where('status', 'ACTIVE')
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $on))
            ->exists();
    }

    /** @return list<int> */
    public function orgUnitAncestors(int $orgUnitId): array
    {
        $on = $this->businessDates->today()->value();

        return OrgUnitClosure::query()
            ->where('descendant_id', $orgUnitId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $on))
            ->orderBy('ancestor_id')
            ->pluck('ancestor_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    public function descendantOrgUnitIds(int $orgUnitId): array
    {
        $on = $this->businessDates->today()->value();

        return OrgUnitClosure::query()
            ->where('ancestor_id', $orgUnitId)
            ->tap(fn ($query) => AsOfQuery::whereValidOn($query, $on))
            ->orderBy('descendant_id')
            ->pluck('descendant_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
