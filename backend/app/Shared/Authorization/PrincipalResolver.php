<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Contracts\Principal;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the IAM-owned principal without coupling controllers to IAM models.
 * When authentication is not yet bound the request is unauthenticated rather
 * than silently anonymous.
 */
final class PrincipalResolver
{
    public function __construct(private readonly Container $container) {}

    public function resolve(): Principal
    {
        // The IAM session middleware stores the verified principal in the
        // request-scoped context. Fall back to a container binding so module
        // tests and background jobs can supply the same contract.
        if ($this->container->bound(AuthenticationContext::class)) {
            $principal = $this->container->make(AuthenticationContext::class)->principalOrNull();

            if ($principal instanceof Principal) {
                return $principal;
            }
        }

        if ($this->container->bound(Principal::class)) {
            $principal = $this->container->make(Principal::class);

            if ($principal instanceof Principal) {
                return $principal;
            }
        }

        throw new AuthenticationException;
    }
}
