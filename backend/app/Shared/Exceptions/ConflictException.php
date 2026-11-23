<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\ErrorCode;

class ConflictException extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::CONFLICT;
    }
}
