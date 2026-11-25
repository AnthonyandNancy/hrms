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
            'version' => (string) config('app.version', '1.0.0'),
            'environment' => (string) app()->environment(),
            'server_time' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'supported_timezones' => array_values((array) config('hrms.supported_timezones', ['UTC', 'Asia/Shanghai'])),
            'api_version' => 'v1',
            'commit' => $this->stable('app.release.commit'),
            'build_time' => $this->stable('app.release.build_time'),
            'spa_build_sha' => $this->stable('app.release.spa_build_sha'),
        ]);
    }

    private function stable(string $key): string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : 'unknown';
    }
}
