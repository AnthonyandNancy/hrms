<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Shared\Authorization\Contracts\AuthorityBindingRepository;
use App\Shared\Authorization\FieldProfile;

/**
 * Resolves the highest live field tier for a reporting capability. Reporting
 * only uses this to decide whether a catalog field exists for the caller; row
 * scope is still enforced exclusively by Authorizer::constrain().
 */
final class FieldTierResolver
{
    public function __construct(private readonly AuthorityBindingRepository $bindings) {}

    public function maxProfile(Principal $principal, string $capability, string $businessDate): FieldProfile
    {
        $max = FieldProfile::P0_PUBLIC;

        foreach ($this->bindings->effectiveBindings(
            $principal->userId(),
            $capability,
            $principal->authVersion(),
            $businessDate,
        ) as $grant) {
            if ($grant->fieldProfile->rank() > $max->rank()) {
                $max = $grant->fieldProfile;
            }
        }

        return $max;
    }

    public function covers(FieldProfile $field, FieldProfile $max): bool
    {
        return $field->rank() <= $max->rank();
    }
}
