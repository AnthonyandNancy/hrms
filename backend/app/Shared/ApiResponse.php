<?php

declare(strict_types=1);

namespace App\Shared;

use Illuminate\Http\JsonResponse;

/**
 * Builds the single API envelope used by every endpoint.
 *
 * Success and failure responses share {code, message, data, request_id}.
 * List payloads are placed inside data as {items, page, page_size, total}.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     */
    public static function ok(mixed $data = null, int $status = 200, string $message = 'OK'): JsonResponse
    {
        return self::make('OK', $message, $status, $data);
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     */
    public static function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return self::make('CREATED', $message, 201, $data);
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     */
    public static function accepted(mixed $data = null, string $message = 'Accepted'): JsonResponse
    {
        return self::make('ACCEPTED', $message, 202, $data);
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $details
     */
    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return self::make($code, $message, $status, $details);
    }

    /**
     * Build a paginated list envelope inside data.
     *
     * @param  list<mixed>  $items
     * @return array{items: list<mixed>, page: int, page_size: int, total: int}
     */
    public static function list(array $items, int $page = 1, int $pageSize = 20, int $total = 0): array
    {
        return [
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>|list<mixed>|null  $data
     */
    private static function make(string $code, string $message, int $status, mixed $data): JsonResponse
    {
        return new JsonResponse([
            'code' => $code,
            'message' => $message,
            'data' => $data ?? [],
            'request_id' => RequestContext::requestId(),
        ], $status);
    }
}
