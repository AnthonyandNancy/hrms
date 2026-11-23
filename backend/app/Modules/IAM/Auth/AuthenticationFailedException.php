<?php

declare(strict_types=1);

namespace App\Modules\IAM\Auth;

use App\Modules\IAM\Contracts\Principal;
use App\Shared\Exceptions\DomainException;
use App\Shared\ErrorCode;

/**
 * Signals a deliberate authentication failure. The envelope uses a stable
 * reason code while never exposing token material or user state.
 */
final class AuthenticationFailedException extends DomainException
{
    public function __construct(string $reason = 'UNAUTHENTICATED', string $message = 'Authentication is required.')
    {
        parent::__construct($message, ['reason' => $reason]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::UNAUTHENTICATED;
    }
}

final class AuthorizationDeniedException extends DomainException
{
    public function __construct(string $reason, string $message = 'You are not allowed to perform this action.')
    {
        parent::__construct($message, ['reason' => $reason]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::FORBIDDEN;
    }
}
