<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\ErrorCode;

/**
 * A 409 business conflict. The stable machine-readable cause lives in
 * data.reason; the top-level code remains CONFLICT for backward compatibility
 * with every existing client and contract test.
 */
class ConflictException extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::CONFLICT;
    }
}
