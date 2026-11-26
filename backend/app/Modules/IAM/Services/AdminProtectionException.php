<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Shared\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * A grant-ceiling refusal: 403 with a stable machine-readable reason so the
 * caller can distinguish an escalation attempt from generic capability denial.
 */
final class AdminProtectionException extends DomainException
{
    public function __construct(string $reason, string $message)
    {
        parent::__construct($message, ['reason' => $reason]);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::FORBIDDEN;
    }
}
