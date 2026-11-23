<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Modules\IAM\Contracts\Principal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fail-closed default used until IAM binds the real capability engine. It
 * denies every capability and yields an empty row scope; it is not an
 * authenticated-user bypass.
 */
final class DenyAllAuthorizer implements Authorizer
{
    public function constrain(Builder $query, Principal $principal, string $capability, string $businessDate): Builder
    {
        return $query->whereRaw('1 = 0');
    }

    public function allows(Principal $principal, string $capability, AnchorRef $anchor, string $businessDate): bool
    {
        return false;
    }

    public function fields(Principal $principal, string $capability, ResourceRef $row): FieldSet
    {
        return new FieldSet([], exportUnlocked: false);
    }
}
