<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Shared\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

final class MetaController
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::ok([
            'version' => (string) config('app.version', '1.0.0-reference'),
            'environment' => (string) app()->environment(),
            'server_time' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'supported_timezones' => array_values((array) config('hrms.supported_timezones', ['UTC', 'Asia/Shanghai'])),
            'api_version' => 'v1',
        ]);
    }
}
