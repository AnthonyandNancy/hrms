<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

/**
 * Records sensitive field access without values. IAM binds the adapter to the
 * platform audit trail; tests can bind a spy.
 */
interface SensitiveAccessAuditor
{
    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $context
     */
    public function record(
        int $userId,
        string $capabilityCode,
        string $resourceType,
        ?int $resourceId = null,
        array $fields = [],
        array $context = [],
    ): void;
}
