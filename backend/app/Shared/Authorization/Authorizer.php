<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use Illuminate\Database\Eloquent\Builder;
use App\Modules\IAM\Contracts\Principal;

interface Authorizer
{
    /**
     * Push the union of allowed row predicates into SQL before pagination,
     * sorting, aggregation or export.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, Principal $p, string $capability, string $businessDate): Builder;

    public function allows(Principal $p, string $capability, AnchorRef $anchor, string $businessDate): bool;

    public function fields(Principal $p, string $capability, ResourceRef $row): FieldSet;
}
