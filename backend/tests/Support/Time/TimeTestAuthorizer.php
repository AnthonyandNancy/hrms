<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Time\Contracts\TimeActorResolver;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use Illuminate\Database\Eloquent\Builder;

/**
 * Test capability engine backed by the same TimeActor the controller uses, so
 * the route guard and the resource scope cannot silently disagree.
 */
final class TimeTestAuthorizer implements Authorizer
{
    public function __construct(private readonly TimeActorResolver $actors) {}

    public function constrain(Builder $query, Principal $p, string $capability, string $businessDate): Builder
    {
        return $query->where('tenant_id', $p->tenantId());
    }

    public function permits(Principal $principal, string $capability, string $businessDate): bool
    {
        return $this->allows($principal, $capability, AnchorRef::self(), $businessDate);
    }

    public function allows(Principal $p, string $capability, AnchorRef $anchor, string $businessDate): bool
    {
        $actor = $this->actors->resolve(app('request'));

        return $actor !== null
            && $actor->tenantId === $p->tenantId()
            && $actor->userId === $p->userId()
            && $actor->hasCapability($capability);
    }

    public function fields(Principal $p, string $capability, ResourceRef $row): FieldSet
    {
        return new FieldSet(['*' => FieldProfile::P0_PUBLIC], exportUnlocked: false);
    }
}
