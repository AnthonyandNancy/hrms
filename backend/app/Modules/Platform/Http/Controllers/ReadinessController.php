<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Services\HealthService;
use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Readiness gates traffic: every dependency required to serve a request must
 * pass. Cache or storage failures return 503 here (unlike legacy /v1/health,
 * which reports them as degraded but still 200).
 */
final class ReadinessController
{
    public function __invoke(HealthService $health): JsonResponse
    {
        $checks = [
            'db' => $health->databaseHealthy(),
            'cache' => $health->cacheHealthy(),
            'storage' => $health->storageHealthy(),
        ];
        $ready = ! in_array(false, $checks, true);

        $payload = [
            'status' => $ready ? 'ready' : 'not_ready',
            'version' => $health->version(),
            'checks' => $checks,
            'time' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        ];

        return $ready
            ? ApiResponse::ok($payload)
            : ApiResponse::error(
                ErrorCode::SERVICE_UNAVAILABLE->value,
                ErrorCode::SERVICE_UNAVAILABLE->defaultMessage(),
                ErrorCode::SERVICE_UNAVAILABLE->status(),
                $payload,
            );
    }
}
