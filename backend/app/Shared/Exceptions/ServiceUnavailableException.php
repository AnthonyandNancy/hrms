<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\ErrorCode;

/**
 * Explicit unavailable dependency. Used when a read model/contract on this
 * deployment cannot answer a metric truthfully, rather than returning zero.
 */
final class ServiceUnavailableException extends DomainException
{
    public function __construct(string $message = 'The service is temporarily unavailable.', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, ['reason' => 'SERVICE_UNAVAILABLE', ...$details], $previous);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::SERVICE_UNAVAILABLE;
    }
}
