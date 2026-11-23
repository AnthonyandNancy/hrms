<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Contracts\Principal;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use Illuminate\Database\Eloquent\Builder;

/**
 * Test authorizer that models the notice rule precisely: the manager may
 * manage notices, every authenticated principal may read, and row scope is
 * left to the audience policy rather than a blanket allow.
 */
final class EngagementAuthorizer implements Authorizer
{
    public function __construct(private readonly int $managerUserId) {}

    public function constrain(Builder $query, Principal $principal, string $capability, string $businessDate): Builder
    {
        return $query;
    }

    public function allows(Principal $principal, string $capability, AnchorRef $anchor, string $businessDate): bool
    {
        if ($capability === 'engagement.notice.manage') {
            return $principal->userId() === $this->managerUserId;
        }

        return in_array($capability, ['engagement.notice.read', 'engagement.notification.read'], true);
    }

    public function fields(Principal $principal, string $capability, ResourceRef $row): FieldSet
    {
        return new FieldSet(['*' => FieldProfile::P0_PUBLIC], exportUnlocked: false);
    }
}
