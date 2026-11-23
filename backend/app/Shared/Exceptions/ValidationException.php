<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\ErrorCode;

class ValidationException extends DomainException
{
    /**
     * @param  array<string, list<string>>  $fields
     */
    public function __construct(array $fields = [], string $message = 'The given data was invalid.')
    {
        parent::__construct($message, ['fields' => $fields]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::VALIDATION_FAILED;
    }
}
