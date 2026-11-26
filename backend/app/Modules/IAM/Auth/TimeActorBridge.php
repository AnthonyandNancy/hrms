<?php

declare(strict_types=1);

namespace App\Modules\IAM\Auth;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Contracts\PrincipalAuthorityReader;
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
            ?? $this->people->employmentForUser($principal->userId());
        $managedEmploymentIds = $employmentId !== null
            ? $this->people->managedEmploymentIds((int) $employmentId, $businessDate)
            : [];

        return new TimeActor(
            userId: $principal->userId(),
            employmentId: $employmentId,
            scope: $this->scope($grants, $managedEmploymentIds),
            capabilities: array_values(array_unique(array_column($grants, 'capability'))),
            managedEmploymentIds: $managedEmploymentIds,
        );
    }

    /**
     * @param  list<array{capability: string, anchor_type: string, anchor_id: int|null, field_profile: string}>  $grants
     * @param  list<int>  $managedEmploymentIds
     */
    private function scope(array $grants, array $managedEmploymentIds): string
    {
        $anchors = array_map(static fn (array $grant): string => strtoupper($grant['anchor_type']), $grants);
        $capabilities = array_column($grants, 'capability');
        $adminScope = in_array('time.attendance.manage', $capabilities, true)
            || in_array('time.leave.admin', $capabilities, true)
            || in_array('COMPANY', $anchors, true)
            || in_array('ORG_UNIT', $anchors, true)
            || in_array('ORG_SUBTREE', $anchors, true);

        if ($adminScope) {
            return 'COMPANY';
        }

        if ($managedEmploymentIds !== [] && in_array('MANAGED', $anchors, true)) {
            return 'MANAGED';
        }

        return 'SELF';
    }
}
