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

final class AllowAllAuthorizer implements Authorizer
{
    /** @var list<array{capability: string, anchor: AnchorRef, on: string}> */
    public array $calls = [];

    /** @var list<string> */
    public array $deniedCapabilities = [];

    /** @var array<string, FieldProfile> */
    public array $fieldProfiles = [];

    public function constrain(Builder $query, Principal $principal, string $capability, string $businessDate): Builder
    {
        $this->calls[] = ['capability' => $capability, 'anchor' => AnchorRef::self(), 'on' => $businessDate];

        return in_array($capability, $this->deniedCapabilities, true) ? $query->whereRaw('1 = 0') : $query;
    }

    public function permits(Principal $principal, string $capability, string $businessDate): bool
    {
        return $this->allows($principal, $capability, AnchorRef::self(), $businessDate);
    }

    public function allows(Principal $principal, string $capability, AnchorRef $anchor, string $businessDate): bool
    {
        $this->calls[] = ['capability' => $capability, 'anchor' => $anchor, 'on' => $businessDate];

        return ! in_array($capability, $this->deniedCapabilities, true);
    }

    public function fields(Principal $principal, string $capability, ResourceRef $row): FieldSet
    {
        $profile = $this->fieldProfiles[$capability] ?? null;
        $amounts = [
            'gross' => FieldProfile::P3_RESTRICTED,
            'net' => FieldProfile::P3_RESTRICTED,
            'deductions' => FieldProfile::P3_RESTRICTED,
            'taxable' => FieldProfile::P3_RESTRICTED,
            'employer_cost' => FieldProfile::P3_RESTRICTED,
            'employee_si' => FieldProfile::P3_RESTRICTED,
            'employee_hf' => FieldProfile::P3_RESTRICTED,
            'tax' => FieldProfile::P3_RESTRICTED,
        ];

        if ($profile === null) {
            throw new \App\Shared\Exceptions\NotFoundOrForbiddenException;
        }

        if ($profile->rank() < FieldProfile::P2_SENSITIVE->rank()) {
            throw new \App\Shared\Exceptions\NotFoundOrForbiddenException;
        }

        return new FieldSet($amounts, restrictedVisible: $profile->rank() >= FieldProfile::P3_RESTRICTED->rank());
    }
}
