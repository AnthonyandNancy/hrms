<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Minimal principal surface Workflow needs. IAM will provide an adapter from
 * its Principal; Workflow never references IAM models.
 */
final readonly class WorkflowPrincipal
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public int $userId,
        public ?int $employmentId,
        public int $authVersion,
        public bool $isPrivileged,
        public array $roles = [],
        public array $capabilities = [],
    ) {}

    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function actorSnapshot(): ActorSnapshot
    {
        return new ActorSnapshot(
            userId: $this->userId,
            employmentId: $this->employmentId,
            authVersion: $this->authVersion,
            isPrivileged: $this->isPrivileged,
            roles: $this->roles,
            capabilities: $this->capabilities,
        );
    }
}
