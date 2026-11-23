<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\ErrorCode;

/**
 * Deliberately renders 404 for both "missing" and "out of authorization scope"
 * so callers cannot enumerate protected resources.
 */
class NotFoundOrForbiddenException extends DomainException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The requested resource was not found.', [], $previous);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::NOT_FOUND;
    }

    public static function message(): string
    {
        return 'The requested resource was not found.';
    }
}
