<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

final class RoleTemplate
{
    /**
     * @param  list<RoleGrant>  $grants
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $purpose,
        public readonly array $grants,
    ) {}

    /**
     * @return list<string>
     */
    public function capabilityCodes(): array
    {
        return array_values(array_unique(array_map(
            static fn (RoleGrant $grant): string => $grant->capabilityCode,
            $this->grants,
        )));
    }
}
