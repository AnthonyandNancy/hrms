<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;

final class FakeOrganizationScopeDirectory implements OrganizationScopeDirectory
{
    /** @var array<int, int> */
    public array $legalEntityByUnit = [];

    /** @var array<int, list<int>> */
    public array $descendants = [];

    /** @var array<int, list<int>> */
    public array $unitsByLegalEntity = [];

    /** @var list<int> */
    public array $knownLegalEntities = [];

    public function isUnitInTenant(int $tenantId, int $orgUnitId, string $businessDate): bool
    {
        return isset($this->legalEntityByUnit[$orgUnitId]);
    }

    public function legalEntityForUnit(int $tenantId, int $orgUnitId, string $businessDate): ?int
    {
        return $this->legalEntityByUnit[$orgUnitId] ?? null;
    }

    public function descendantUnitIds(int $tenantId, int $orgUnitId, string $businessDate): array
    {
        return $this->descendants[$orgUnitId] ?? [$orgUnitId];
    }

    public function unitIdsForLegalEntity(int $tenantId, int $legalEntityId, string $businessDate): array
    {
        return $this->unitsByLegalEntity[$legalEntityId] ?? [];
    }

    public function legalEntityExists(int $tenantId, int $legalEntityId): bool
    {
        return in_array($legalEntityId, $this->knownLegalEntities, true);
    }
}
