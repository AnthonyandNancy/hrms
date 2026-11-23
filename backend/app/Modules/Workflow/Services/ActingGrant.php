<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

/**
 * One resolved way of acting on a task: direct, delegated or admin override.
 * Immutable so it can safely be recorded in the decision snapshot.
 */
final readonly class ActingGrant
{
    public function __construct(
        public ?int $delegationId = null,
        public ?int $principalUserId = null,
        public bool $adminOverride = false,
        public ?string $reason = null,
    ) {}

    public function isDelegation(): bool
    {
        return $this->delegationId !== null && $this->principalUserId !== null;
    }

    public static function admin(int $userId, string $reason): self
    {
        return new self(adminOverride: true, reason: $reason, principalUserId: $userId);
    }
}
