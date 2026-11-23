<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\ErrorCode;
use RuntimeException;

/**
 * Base class for expected business failures. Renderers use errorCode()
 * rather than exception class names or messages to build the public response.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message = '',
        private readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    abstract public function errorCode(): ErrorCode;

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
