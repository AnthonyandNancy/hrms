<?php

declare(strict_types=1);

namespace App\Shared\Commands;

/**
 * A business rule that must hold before a command mutates state. Invariants
 * receive the resolved tenant and the validated payload, and signal a
 * violation by throwing a domain exception.
 */
interface Invariant
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function assert(int $tenantId, array $payload): void;
}
