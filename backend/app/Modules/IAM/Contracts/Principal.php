<?php

declare(strict_types=1);

namespace App\Modules\IAM\Contracts;

/**
 * Authentication principal exposed to other modules. IAM owns its only
 * implementation; modules depend on this interface and never on the User model.
 */
interface Principal
{
    public function userId(): int;

    public function tenantId(): int;

    public function employmentId(): ?int;

    public function authVersion(): int;

    public function isPrivileged(): bool;

    public function hasMfa(): bool;

    public function sessionId(): ?string;

    public function breakGlassGrantId(): ?int;
}
