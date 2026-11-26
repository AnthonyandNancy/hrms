<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Models\Notice;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Contracts\UserAudienceDirectory;
use App\Modules\People\Contracts\NoticeAudienceDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Time\Clock;

final class NoticeAccessPolicy
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly PeopleDirectory $people,
        private readonly NoticeAudienceDirectory $audience,
        private readonly UserAudienceDirectory $users,
        private readonly Clock $clock,
    ) {}

    public function canManage(Principal $principal, Notice $notice, string $businessDate): bool
    {
        if ((int) $notice->created_by === $principal->userId()) {
            return true;
        }

        return $this->canManageAudience($principal, (array) $notice->audience_json, $businessDate);
    }

    /**
     * Manage authority is evaluated through the frozen authorizer, never by
     * comparing role names or trusting client audience claims.
     *
     * @param  array<string, mixed>  $audience
     */
    public function canManageAudience(Principal $principal, array $audience, string $businessDate): bool
    {
        $employmentId = $principal->employmentId()
            ?? $this->people->employmentForUser($principal->tenantId(), $principal->userId());
        $snapshot = $employmentId !== null
            ? $this->people->employmentSnapshot($principal->tenantId(), $employmentId, $businessDate)
            : null;

        if ($snapshot !== null
            && $this->authorizer->allows($principal, 'engagement.notice.manage', AnchorRef::company(), $businessDate)) {
            return true;
        }

        $orgIds = array_map(static fn (mixed $id): int => (int) $id, (array) ($audience['org_unit_ids'] ?? []));

        foreach (array_values(array_unique($orgIds)) as $orgId) {
            if ($orgId <= 0) {
                continue;
            }

            if ($this->authorizer->allows($principal, 'engagement.notice.manage', AnchorRef::orgUnit($orgId), $businessDate)
                || $this->authorizer->allows($principal, 'engagement.notice.manage', AnchorRef::orgSubtree($orgId), $businessDate)) {
                return true;
            }
        }

        if ($snapshot?->orgUnitId !== null
            && $this->authorizer->allows($principal, 'engagement.notice.manage', AnchorRef::orgUnit((int) $snapshot->orgUnitId), $businessDate)) {
            return true;
        }

        return $this->authorizer->allows($principal, 'engagement.notice.manage', AnchorRef::self(), $businessDate);
    }

    public function isTarget(Principal $principal, Notice $notice, string $businessDate): bool
    {
        $audience = (array) $notice->audience_json;
        $type = strtoupper((string) ($audience['type'] ?? ''));
        $employmentId = $principal->employmentId()
            ?? $this->people->employmentForUser($principal->tenantId(), $principal->userId());

        return match ($type) {
            'ALL' => true,
            'ROLE' => array_intersect(
                array_map(static fn (mixed $code): string => (string) $code, (array) ($audience['role_codes'] ?? [])),
                $this->users->roleCodesForUser($principal->tenantId(), $principal->userId(), $businessDate),
            ) !== [],
            'ORG' => $employmentId !== null
                && array_intersect(
                    $this->audience->employmentOrgUnitIds($principal->tenantId(), $employmentId, $businessDate),
                    array_map('intval', (array) ($audience['org_unit_ids'] ?? [])),
                ) !== [],
            default => false,
        };
    }

    public function canRead(Principal $principal, Notice $notice, string $businessDate): bool
    {
        if ($this->canManage($principal, $notice, $businessDate)) {
            return true;
        }

        return $notice->status === Notice::STATUS_PUBLISHED
            && $notice->publish_at <= $this->clock->now()
            && $this->isTarget($principal, $notice, $businessDate);
    }
}
