<?php

declare(strict_types=1);

namespace App\Shared\Commands;

/**
 * Outcome of a command. `status` is 200 for an idempotent replay regardless of
 * the original creation status, so clients can distinguish a retry from a
 * fresh write without diffing bodies.
 */
final class CommandResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly bool $replayed = false,
    ) {}
}
