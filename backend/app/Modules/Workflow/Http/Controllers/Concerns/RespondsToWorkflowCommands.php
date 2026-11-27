<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controllers\Concerns;

use App\Shared\ApiResponse;
use App\Shared\Commands\CommandResult;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RespondsToWorkflowCommands
{
    protected function commandResponse(CommandResult $result): JsonResponse
    {
        $status = $result->replayed ? 200 : $result->status;

        $response = match ($status) {
            201 => ApiResponse::created($result->body),
            202 => ApiResponse::accepted($result->body),
            default => ApiResponse::ok($result->body, $status),
        };

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    protected function workflowIdempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '' || mb_strlen($key) > 128) {
            throw new ValidationException(
                ['idempotency_key' => ['A valid Idempotency-Key header is required (1-128 characters).']],
                'A valid Idempotency-Key header is required.',
                ['reason' => 'IDEMPOTENCY_KEY_REQUIRED'],
            );
        }

        return trim($key);
    }
}
