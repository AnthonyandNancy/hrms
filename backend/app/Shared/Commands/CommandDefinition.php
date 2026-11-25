<?php

declare(strict_types=1);

namespace App\Shared\Commands;

use App\Shared\Authorization\AnchorType;

/**
 * Declarative description of one write command. The definition is data only:
 * capability enforcement happens in route middleware, while invariants are
 * evaluated by CommandRunner inside the same transaction as the write.
 */
final class CommandDefinition
{
    /**
     * @param  list<class-string<Invariant>>  $invariants
     */
    public function __construct(
        public readonly string $name,
        public readonly string $capability,
        public readonly AnchorType $anchor,
        public readonly bool $idempotent,
        public readonly array $invariants,
        public readonly ?string $effect,
        public readonly string $auditAction,
    ) {}
}
