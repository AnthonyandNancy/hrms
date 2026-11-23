<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\ErrorCode;

class ValidationException extends DomainException
{
    /**
     * @param  array<string, list<string>>  $fields
     */
    public function __construct(
        array $fields = [],
        string $message = 'The given data was invalid.',
        private readonly ?string $reason = null,
    ) {
        parent::__construct($message, ['fields' => $fields]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::VALIDATION_FAILED;
    }

    /**
     * Attach a stable machine-readable reason without changing the field
     * validation payload shape used by clients.
     */
    public function withReason(string $reason): self
    {
        return new self(
            (array) ($this->details()['fields'] ?? []),
            $this->getMessage(),
            $reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $details = parent::details();

        if ($this->reason !== null) {
            $details['reason'] = $this->reason;
        }

        return $details;
    }
}
