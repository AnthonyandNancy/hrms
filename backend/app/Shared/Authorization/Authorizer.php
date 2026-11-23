<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Modules\IAM\Contracts\Principal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Frozen authorization seam shared with IAM (Task 2). Implementations must
 * push row predicates into SQL; callers never serialize then filter.
 */
interface Authorizer
{
    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function constrain(Builder $query, Principal $principal, string $capability, string $businessDate): Builder;

    public function allows(Principal $principal, string $capability, AnchorRef $anchor, string $businessDate): bool;

    public function fields(Principal $principal, string $capability, ResourceRef $row): FieldSet;
}
