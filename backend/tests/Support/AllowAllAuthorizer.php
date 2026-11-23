<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Contracts\Principal;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use Illuminate\Database\Eloquent\Builder;

final class AllowAllAuthorizer implements Authorizer
{
    /** @var list<array{capability: string, anchor: AnchorRef, on: string}> */
    public array $calls = [];

    /** @var list<string> */
    public array $deniedCapabilities = [];

    public function constrain(Builder $query, Principal $principal, string $capability, string $businessDate): Builder
    {
        $this->calls[] = ['capability' => $capability, 'anchor' => AnchorRef::global(), 'on' => $businessDate];

        return in_array($capability, $this->deniedCapabilities, true) ? $query->whereRaw('1 = 0') : $query;
    }

    public function allows(Principal $principal, string $capability, AnchorRef $anchor, string $businessDate): bool
    {
        $this->calls[] = ['capability' => $capability, 'anchor' => $anchor, 'on' => $businessDate];

        return ! in_array($capability, $this->deniedCapabilities, true);
    }

    public function fields(Principal $principal, string $capability, ResourceRef $row): FieldSet
    {
        return new FieldSet(
            visible: ['*'],
            filterable: ['*'],
            exportable: ['*'],
        );
    }
}
