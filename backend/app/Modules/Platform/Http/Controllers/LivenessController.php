<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Shared\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Liveness must never touch the database, cache, storage or network. A slow
 * dependency must not cause an otherwise healthy container to be restarted.
 */
final class LivenessController
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::ok([
            'status' => 'ok',
            'version' => (string) config('app.version', '1.0.0-reference'),
            'time' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        ]);
    }
}
