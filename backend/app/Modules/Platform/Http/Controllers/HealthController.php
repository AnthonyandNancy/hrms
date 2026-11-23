<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Services\HealthService;
use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function __invoke(HealthService $health): JsonResponse
    {
        $db = $health->databaseHealthy();
        $cache = $health->cacheHealthy();
        $storage = $health->storageHealthy();

        $payload = [
            'status' => $db ? (($cache && $storage) ? 'ok' : 'degraded') : 'unavailable',
            'version' => $health->version(),
            'checks' => [
                'db' => $db,
                'cache' => $cache,
                'storage' => $storage,
            ],
            'time' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        ];

        if (! $db) {
            return ApiResponse::error(
                ErrorCode::SERVICE_UNAVAILABLE->value,
                ErrorCode::SERVICE_UNAVAILABLE->defaultMessage(),
                ErrorCode::SERVICE_UNAVAILABLE->status(),
                $payload,
            );
        }

        return ApiResponse::ok($payload);
    }
}
