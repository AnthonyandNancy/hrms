<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\ApiResponse;
use App\Shared\ErrorCode;

/**
 * A SELF capability is meaningful only when the account has a live
 * employment. This is a business-state conflict, not a missing resource:
 * the account exists, authentication succeeded and authorization may also
 * have succeeded. Returning 404 hides the actionable cause and makes an
 * administrator's self-service pages look broken.
 */
final class NoActiveEmploymentException extends DomainException
{
    public function __construct(?string $capability = null)
    {
        parent::__construct(
            'The current account is not linked to an active employment.',
            array_filter([
                'reason' => 'NO_ACTIVE_EMPLOYMENT',
                'capability' => $capability,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CONFLICT;
    }

    public function render(): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::error(
            'NO_ACTIVE_EMPLOYMENT',
            $this->getMessage(),
            409,
            $this->details(),
        );
    }
}
