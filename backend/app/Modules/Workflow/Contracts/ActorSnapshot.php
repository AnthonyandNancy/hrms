<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Authorization facts captured at decision time. The auth version is evidence
 * that the actor was authorized under a specific IAM session generation.
 */
final readonly class ActorSnapshot
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'employment_id' => $this->employmentId,
            'auth_version' => $this->authVersion,
            'is_privileged' => $this->isPrivileged,
            'roles' => array_values($this->roles),
            'capabilities' => array_values($this->capabilities),
        ];
    }
}
