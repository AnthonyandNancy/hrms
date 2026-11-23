<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\IAM\Contracts\Principal;

/**
 * Minimal principal for a queued export. Capabilities are intentionally empty;
 * this object only identifies the tenant/requester and cannot escalate.
 */
final readonly class ExportPrincipal implements Principal
{
    public function __construct(
        private int $tenantId,
        private int $userId,
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
        return 1;
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
