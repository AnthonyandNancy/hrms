<?php

declare(strict_types=1);

namespace App\Modules\IAM\Auth;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Contracts\PrincipalAuthorityReader;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Contracts\TimeActorResolver;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Illuminate\Http\Request;

/**
 * IAM-owned bridge that maps a live session principal and its effective
 * authority bindings into Time's narrow actor. Time never imports IAM
 * internals, and IAM only imports Time Contracts.
 */
final class TimeActorBridge implements TimeActorResolver
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly PrincipalAuthorityReader $authorities,
        private readonly PeopleDirectory $people,
        private readonly OrganizationDirectory $organization,
        private readonly Clock $clock,
    ) {}

    public function resolve(Request $request): ?TimeActor
    {
        $principal = $this->context->principalOrNull();

        if ($principal === null) {
            return null;
        }

        $businessDate = BusinessDate::today(
            (string) config('hrms.default_timezone', 'Asia/Shanghai'),
            $this->clock,
        )->value();
        $grants = $this->authorities->grants($principal, $businessDate);
        $employmentId = $principal->employmentId()
            ?? $this->people->employmentForUser($principal->tenantId(), $principal->userId());
        $managedEmploymentIds = $employmentId !== null
            ? $this->people->managedEmploymentIds($principal->tenantId(), $employmentId, $businessDate)
            : [];
        $legalEntityIds = $this->legalEntityIds($principal, $grants, $businessDate);

        return new TimeActor(
            userId: $principal->userId(),
            tenantId: $principal->tenantId(),
            employmentId: $employmentId,
            scope: $this->scope($grants, $managedEmploymentIds, $legalEntityIds),
            capabilities: array_values(array_unique(array_column($grants, 'capability'))),
            managedEmploymentIds: $managedEmploymentIds,
            legalEntityIds: $legalEntityIds,
        );
    }

    /**
     * @param  list<array{capability: string, anchor_type: string, anchor_id: int|null, field_profile: string}>  $grants
     * @return list<int>
     */
    private function legalEntityIds(Principal $principal, array $grants, string $businessDate): array
    {
        $ids = [];

        foreach ($grants as $grant) {
            $anchor = strtoupper($grant['anchor_type']);
            $anchorId = $grant['anchor_id'];

            if ($anchor === 'COMPANY') {
                // The single company has no numeric anchor. Preserve the
                // company-wide scope flag by using the sentinel 0; callers
                // treat any non-empty id set as "entire company" while the
                // physical legal_entity_id columns are migrated away.
                $ids[] = $anchorId ?? 0;
            }

            if (in_array($anchor, ['ORG_UNIT', 'ORG_SUBTREE'], true) && $anchorId !== null) {
                $entityId = $this->organization->legalEntityOfOrg($principal->tenantId(), $anchorId, $businessDate);

                if ($entityId !== null) {
                    $ids[] = $entityId;
                }
            }
        }

        $employmentId = $principal->employmentId()
            ?? $this->people->employmentForUser($principal->tenantId(), $principal->userId());

        if ($employmentId !== null) {
            $ids = [...$ids, ...$this->people->legalEntityIdsForEmployment($principal->tenantId(), $employmentId)];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @param  list<array{capability: string, anchor_type: string, anchor_id: int|null, field_profile: string}>  $grants
     * @param  list<int>  $managedEmploymentIds
     * @param  list<int>  $legalEntityIds
     */
    private function scope(array $grants, array $managedEmploymentIds, array $legalEntityIds): string
    {
        $anchors = array_map(static fn (array $grant): string => strtoupper($grant['anchor_type']), $grants);
        $capabilities = array_column($grants, 'capability');
        $adminScope = in_array('time.attendance.manage', $capabilities, true)
            || in_array('time.leave.admin', $capabilities, true)
            || in_array('COMPANY', $anchors, true)
            || in_array('ORG_UNIT', $anchors, true)
            || in_array('ORG_SUBTREE', $anchors, true);

        if ($adminScope && $legalEntityIds !== []) {
            return 'COMPANY';
        }

        if ($managedEmploymentIds !== [] && in_array('MANAGED', $anchors, true)) {
            return 'MANAGED';
        }

        return 'SELF';
    }
}
