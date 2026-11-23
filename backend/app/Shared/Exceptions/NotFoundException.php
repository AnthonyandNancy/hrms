<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\ErrorCode;

class NotFoundException extends DomainException
{
    public function __construct(string $message = 'The requested resource was not found.', ?\Throwable $previous = null)
    {
        parent::__construct($message, [], $previous);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::NOT_FOUND;
    }

    public static function forResource(): self
    {
        return new self;
    }
}
