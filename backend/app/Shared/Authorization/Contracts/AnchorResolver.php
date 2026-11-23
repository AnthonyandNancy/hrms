<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

use Illuminate\Database\Eloquent\Builder;
use App\Shared\Authorization\Principal;

/**
 * Resolve one anchor to a row predicate and an optional field-profile floor.
 * Implementations must never return user-supplied SQL.
 */
interface AnchorResolver
{
    /**
     * Whether the anchor is effective on the business date for this principal.
     */
    public function allows(Principal $principal, string $capability, int $anchorId, string $businessDate): bool;

    /**
     * Add a tenant-safe predicate selecting rows visible through the anchor.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function constrain(Builder $query, Principal $principal, string $capability, int $anchorId, string $businessDate): void;
}
