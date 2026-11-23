<?php

declare(strict_types=1);

namespace App\Modules\IAM\Auth;

use App\Modules\IAM\Contracts\Principal;
use Illuminate\Http\Request;

/**
 * Request-scoped authentication context. The middleware populates it after
 * verifying the session; controllers never read raw cookies themselves.
 */
final class AuthenticationContext
{
    private ?Principal $principal = null;

    private ?int $breakGlassGrantId = null;

    public function set(Principal $principal, ?int $breakGlassGrantId = null): void
    {
        $this->principal = $principal;
        $this->breakGlassGrantId = $breakGlassGrantId;
    }

    public function clear(): void
    {
        $this->principal = null;
        $this->breakGlassGrantId = null;
    }

    public function requirePrincipal(): Principal
    {
        if ($this->principal === null) {
            throw new AuthenticationFailedException;
        }

        return $this->principal;
    }

    public function principalOrNull(): ?Principal
    {
        return $this->principal;
    }

    public function breakGlassGrantId(): ?int
    {
        return $this->breakGlassGrantId;
    }
}
