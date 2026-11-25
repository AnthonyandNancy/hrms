<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException as LaravelValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Converts framework and domain exceptions into the stable API envelope.
 * Internal exception messages, stack traces, SQL and paths are never exposed.
 */
final class ExceptionRenderer
{
    public function render(Throwable $exception): \Illuminate\Http\JsonResponse
    {
        if ($exception instanceof NoActiveEmploymentException) {
            return $exception->render();
        }

        if ($exception instanceof DomainException) {
            return ApiResponse::error(
                $exception->errorCode()->value,
                $exception->getMessage() !== '' ? $exception->getMessage() : $exception->errorCode()->defaultMessage(),
                $exception->errorCode()->status(),
                $exception->details(),
            );
        }

        if ($exception instanceof LaravelValidationException) {
            return ApiResponse::error(
                ErrorCode::VALIDATION_FAILED->value,
                ErrorCode::VALIDATION_FAILED->defaultMessage(),
                ErrorCode::VALIDATION_FAILED->status(),
                ['fields' => $exception->errors()],
            );
        }

        if ($exception instanceof AuthenticationException) {
            return $this->standard(ErrorCode::UNAUTHENTICATED);
        }

        if ($exception instanceof AuthorizationException || $exception instanceof AccessDeniedHttpException) {
            return $this->standard(ErrorCode::FORBIDDEN);
        }

        if ($exception instanceof MethodNotAllowedHttpException) {
            return $this->standard(ErrorCode::METHOD_NOT_ALLOWED);
        }

        if ($exception instanceof NotFoundHttpException) {
            return $this->standard(ErrorCode::NOT_FOUND);
        }

        if ($exception instanceof TooManyRequestsHttpException) {
            return $this->standard(ErrorCode::RATE_LIMITED);
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            return match ($status) {
                401 => $this->standard(ErrorCode::UNAUTHENTICATED),
                403 => $this->standard(ErrorCode::FORBIDDEN),
                404 => $this->standard(ErrorCode::NOT_FOUND),
                405 => $this->standard(ErrorCode::METHOD_NOT_ALLOWED),
                409 => $this->standard(ErrorCode::CONFLICT),
                422 => $this->standard(ErrorCode::VALIDATION_FAILED),
                429 => $this->standard(ErrorCode::RATE_LIMITED),
                503 => $this->standard(ErrorCode::SERVICE_UNAVAILABLE),
                default => $this->genericHttp($status),
            };
        }

        return $this->standard(ErrorCode::INTERNAL);
    }

    private function standard(ErrorCode $code): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::error($code->value, $code->defaultMessage(), $code->status());
    }

    private function genericHttp(int $status): \Illuminate\Http\JsonResponse
    {
        $status = $status >= 400 && $status <= 599 ? $status : 500;
        $message = Response::$statusTexts[$status] ?? 'An unexpected error occurred.';

        return ApiResponse::error('HTTP_ERROR', $message, $status);
    }
}
