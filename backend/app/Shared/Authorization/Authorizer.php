<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Modules\IAM\Contracts\Principal;
use Illuminate\Database\Eloquent\Builder;

interface Authorizer
{
    /**
     * Push the union of allowed row predicates into SQL before pagination,
     * sorting, aggregation or export.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, Principal $p, string $capability, string $businessDate): Builder;

    /**
     * Feature-level check: does the principal hold this capability under ANY
     * effective anchor on the business date? Use it only for route gates whose
     * actual row scope is applied separately with constrain() or a concrete
     * resource anchor. It must never replace a resource-level allows() check.
     */
    public function permits(Principal $p, string $capability, string $businessDate): bool;

    public function allows(Principal $p, string $capability, AnchorRef $anchor, string $businessDate): bool;

    public function fields(Principal $p, string $capability, ResourceRef $row): FieldSet;
}
