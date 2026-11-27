<?php

declare(strict_types=1);

namespace App\Modules\IAM\Auth;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;

/**
 * Immutable request principal. Constructing one requires a live user row and a
 * session whose auth_version has already been verified.
 */
final class AuthenticatedPrincipal implements Principal
{
    public function __construct(
        private readonly int $userId,
        private readonly ?int $employmentId,
        private readonly int $authVersion,
        private readonly bool $privileged,
        private readonly bool $mfa,
        private readonly ?string $sessionId = null,
        private readonly ?int $breakGlassGrantId = null,
    ) {}

    public static function fromSession(User $user, Session $session, ?int $employmentId = null, ?int $grantId = null, ?bool $mfa = null): self
    {
        return new self(
            userId: (int) $user->id,
            employmentId: $employmentId,
            authVersion: (int) $session->auth_version,
            privileged: (bool) $user->is_privileged,
            mfa: $mfa ?? (bool) $session->mfa_verified,
            sessionId: (string) $session->id,
            breakGlassGrantId: $grantId,
        );
    }

    public function userId(): int
    {
        return $this->userId;
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
