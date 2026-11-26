<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;

final class FakeOrganizationScopeDirectory implements OrganizationScopeDirectory
{
    /** @var array<int, true> */
    public array $knownUnits = [];

    /** @var array<int, list<int>> */
    public array $descendants = [];

    /** @var array<int, list<int>> */
    public array $ancestors = [];

    public function orgUnitExists(int $orgUnitId): bool
    {
        return isset($this->knownUnits[$orgUnitId]);
    }

    public function orgUnitAncestors(int $orgUnitId): array
    {
        return $this->ancestors[$orgUnitId] ?? [$orgUnitId];
    }

    public function descendantOrgUnitIds(int $orgUnitId): array
    {
        return $this->descendants[$orgUnitId] ?? [$orgUnitId];
    }
}
