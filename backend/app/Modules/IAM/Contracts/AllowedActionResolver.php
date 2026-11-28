<?php

declare(strict_types=1);

namespace App\Modules\IAM\Contracts;

/**
 * Server-owned projection of actions permitted for one concrete business
 * object. Clients may render these keys but must not derive them locally.
 */
interface AllowedActionResolver
{
    /**
     * @param  array<string, mixed>  $state  server-loaded object state, including
     *                                       optional subject_employment_id, org_unit_id and case_id
     * @return list<string>
     */
    public function for(
        Principal $principal,
        string $objectType,
        int|string $objectId,
        array $state,
        string $businessDate,
    ): array;
}
