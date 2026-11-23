<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Contracts\Principal;

final readonly class TestPrincipal implements Principal
{
    public function __construct(
        private int $tenantId,
        private int $userId,
        private ?int $employmentId = null,
        private int $authVersion = 1,
        private bool $privileged = false,
        private bool $mfa = true,
        private ?string $sessionId = 'test-session',
        private ?int $breakGlassGrantId = null,
    ) {}

    public function userId(): int
    {
        return $this->userId;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function employmentId(): ?int
    {
        return $this->employmentId;
    }

    public function authVersion(): int
    {
        return $this->authVersion;
    }

    public function isPrivileged(): bool
    {
        return $this->privileged;
    }

    public function hasMfa(): bool
    {
        return $this->mfa;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function breakGlassGrantId(): ?int
    {
        return $this->breakGlassGrantId;
    }
}
