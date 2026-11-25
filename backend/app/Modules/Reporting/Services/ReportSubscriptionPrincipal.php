<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\IAM\Contracts\Principal;

/**
 * Narrow principal used only to re-evaluate a subscription recipient's live
 * authority. It carries no caller-supplied capability or scope.
 */
final readonly class ReportSubscriptionPrincipal implements Principal
{
    public function __construct(
        private int $tenantId,
        private int $userId,
        private int $authVersion,
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
        return null;
    }

    public function authVersion(): int
    {
        return $this->authVersion;
    }

    public function isPrivileged(): bool
    {
        return false;
    }

    public function hasMfa(): bool
    {
        return false;
    }

    public function sessionId(): ?string
    {
        return null;
    }

    public function breakGlassGrantId(): ?int
    {
        return null;
    }
}
