<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\ErrorCode;

class ValidationException extends DomainException
{
    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>  $details
     */
    public function __construct(array $fields = [], string $message = 'The given data was invalid.', array $details = [])
    {
        parent::__construct($message, ['fields' => $fields, ...$details]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::VALIDATION_FAILED;
    }
}
