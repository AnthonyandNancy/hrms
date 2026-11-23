<?php

declare(strict_types=1);

namespace App\Modules\IAM\Contracts;

/**
 * Read-only projection of the caller's effective authority bindings. Other
 * modules may use this contract to build a narrow actor view without importing
 * IAM models or the authorization engine internals.
 */
interface PrincipalAuthorityReader
{
    /**
     * @return list<array{capability: string, anchor_type: string, anchor_id: int|null, field_profile: string}>
     */
    public function grants(Principal $principal, string $businessDate): array;
}
