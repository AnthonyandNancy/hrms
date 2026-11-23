<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Services\IdempotencyService;
use App\Shared\Auth\AuthenticatedPrincipal;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the platform idempotency ledger to command routes when the client
 * supplies Idempotency-Key. Replays return the original status/body.
 *
 * The middleware deliberately depends on the Shared principal contract rather
 * than IAM: Platform must not import a business module.
 */
final class IdempotentCommand
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly Container $container,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get('Idempotency-Key');

        if ($key === null || trim($key) === '') {
            return $next($request);
        }

        if (mb_strlen($key) > 128) {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'The given data was invalid.',
                'data' => ['fields' => ['Idempotency-Key' => ['The idempotency key may not exceed 128 characters.']]],
                'request_id' => (string) $request->attributes->get('request_id', ''),
            ], 422);
        }

        if (! $this->container->bound(AuthenticatedPrincipal::class)) {
            return $next($request);
        }

        $principal = $this->container->make(AuthenticatedPrincipal::class);

        if (! $principal instanceof AuthenticatedPrincipal) {
            return $next($request);
        }

        $result = $this->idempotency->run(
            tenantId: $principal->tenantId(),
            principalId: $principal->userId(),
            scope: $request->method().':'.$request->path(),
            key: $key,
            payload: $request->all(),
            operation: function () use ($request, $next): array {
                $response = $next($request);

                if (! $response instanceof JsonResponse) {
                    return ['http_status' => $response->getStatusCode(), 'body' => []];
                }

                /** @var array<string, mixed> $body */
                $body = (array) $response->getData(true);

                return ['http_status' => $response->getStatusCode(), 'body' => $body];
            },
        );

        $response = new JsonResponse($result->body, $result->status);

        if ($result->replayed) {
            $response->headers->set('Idempotent-Replayed', 'true');
        }

        return $response;
    }
}
