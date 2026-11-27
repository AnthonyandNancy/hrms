<?php

declare(strict_types=1);

namespace App\Modules\IAM\Contracts;

use App\Shared\Auth\AuthenticatedPrincipal;

/**
 * Authentication principal exposed to other modules. IAM owns its only
 * implementation; modules depend on this interface and never on the User model.
 */
interface Principal extends AuthenticatedPrincipal
{
    public function userId(): int;

    public function employmentId(): ?int;

    public function authVersion(): int;

    public function isPrivileged(): bool;

    public function hasMfa(): bool;

    public function sessionId(): ?string;

    public function breakGlassGrantId(): ?int;
}
