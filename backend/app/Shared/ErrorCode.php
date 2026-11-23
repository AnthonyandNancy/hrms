<?php

declare(strict_types=1);

namespace App\Shared;

/**
 * Stable machine-readable API error codes. HTTP status is derived from the
 * case so renderers cannot drift from the documented contract.
 */
enum ErrorCode: string
{
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case FORBIDDEN = 'FORBIDDEN';
    case NOT_FOUND = 'NOT_FOUND';
    case METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    case CONFLICT = 'CONFLICT';
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case RATE_LIMITED = 'RATE_LIMITED';
    case INTERNAL = 'INTERNAL';
    case SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    public function status(): int
    {
        return match ($this) {
            self::UNAUTHENTICATED => 401,
            self::FORBIDDEN => 403,
            self::NOT_FOUND => 404,
            self::METHOD_NOT_ALLOWED => 405,
            self::CONFLICT => 409,
            self::VALIDATION_FAILED => 422,
            self::RATE_LIMITED => 429,
            self::INTERNAL => 500,
            self::SERVICE_UNAVAILABLE => 503,
        };
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::UNAUTHENTICATED => 'Authentication is required.',
            self::FORBIDDEN => 'You are not allowed to perform this action.',
            self::NOT_FOUND => 'The requested resource was not found.',
            self::METHOD_NOT_ALLOWED => 'The HTTP method is not supported for this endpoint.',
            self::CONFLICT => 'The request conflicts with the current state.',
            self::VALIDATION_FAILED => 'The given data was invalid.',
            self::RATE_LIMITED => 'Too many requests. Please try again later.',
            self::INTERNAL => 'An unexpected error occurred.',
            self::SERVICE_UNAVAILABLE => 'The service is temporarily unavailable.',
        };
    }
}
