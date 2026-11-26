<?php

declare(strict_types=1);

namespace App\Shared\Commands;

/**
 * A business rule that must hold before a command mutates state. Invariants
 * receive the validated payload and signal a violation by throwing a domain
 * exception.
 */
interface Invariant
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function assert(array $payload): void;
}
