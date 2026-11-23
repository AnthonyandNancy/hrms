<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

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
        if (! $this->container->bound(Principal::class)) {
            throw new AuthenticationException;
        }

        $principal = $this->container->make(Principal::class);

        if (! $principal instanceof Principal) {
            throw new AuthenticationException;
        }

        return $principal;
    }
}
