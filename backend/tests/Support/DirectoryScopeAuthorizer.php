<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use Illuminate\Database\Eloquent\Builder;

/**
 * Test consumer of the frozen directories. This intentionally derives row
 * scope through the public contracts rather than People/Organization models,
 * mirroring what IAM's real anchor resolvers must do.
 */
final class DirectoryScopeAuthorizer implements Authorizer
{
    public function __construct(
        private readonly PeopleDirectory $people,
        private readonly OrganizationDirectory $organization,
        private readonly Principal $principal,
        private readonly string $scope,
        private readonly int|string|null $anchorId = null,
    ) {}

    public function constrain(Builder $query, Principal $principal, string $capability, string $businessDate): Builder
    {
        $ids = match ($this->scope) {
            'SELF' => array_values(array_filter([$principal->employmentId()])),
            'MANAGED' => $principal->employmentId() === null
                ? []
                : $this->people->managedEmploymentIds($principal->tenantId(), $principal->employmentId(), $businessDate),
            'ORG_UNIT' => $this->employmentIdsInOrg((int) $this->anchorId, $businessDate, false),
            'ORG_SUBTREE' => $this->employmentIdsInOrg((int) $this->anchorId, $businessDate, true),
            'COMPANY' => $this->people->employmentIdsForLegalEntities($principal->tenantId(), [(int) $this->anchorId], $businessDate),
            default => [],
        };

        return $query->whereIn('employments.id', $ids);
    }

    public function permits(Principal $principal, string $capability, string $businessDate): bool
    {
        return $this->allows($principal, $capability, AnchorRef::self(), $businessDate);
    }

    public function allows(Principal $principal, string $capability, AnchorRef $anchor, string $businessDate): bool
    {
        return true;
    }

    public function fields(Principal $principal, string $capability, ResourceRef $row): FieldSet
    {
        return new FieldSet([
            '*' => \App\Shared\Authorization\FieldProfile::P0_PUBLIC,
        ], exportUnlocked: true);
    }

    /**
     * @return list<int>
     */
    private function employmentIdsInOrg(int $orgId, string $on, bool $subtree): array
    {
        $orgIds = $subtree
            ? $this->organization->subtreeIds($this->principal->tenantId(), $orgId, $on)
            : $this->organization->orgUnitIds($this->principal->tenantId(), [$orgId], $on);

        if ($orgIds === []) {
            return [];
        }

        $legalEntityId = $this->organization->legalEntityOfOrg($this->principal->tenantId(), $orgId, $on);

        if ($legalEntityId === null) {
            return [];
        }

        $ids = $this->people->employmentIdsForLegalEntities($this->principal->tenantId(), [$legalEntityId], $on);
        $matches = [];

        foreach ($ids as $employmentId) {
            $snapshot = $this->people->employmentSnapshot($this->principal->tenantId(), $employmentId, $on);

            if ($snapshot !== null && in_array($snapshot->orgUnitId, $orgIds, true)) {
                $matches[] = $employmentId;
            }
        }

        return $matches;
    }
}
