<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/**
 * Minimal identity seam used by platform infrastructure. IAM's richer
 * Principal implements this contract so Platform never imports a business
 * module.
 */
interface AuthenticatedPrincipal
{
    public function userId(): int;
}
