<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Services\DashboardService;
use App\Modules\Reporting\Services\MetricQueryBuilder;
use App\Shared\ApiResponse;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Time\TenantBusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly TenantBusinessDate $businessDates,
        private readonly DashboardService $dashboard,
        private readonly MetricQueryBuilder $metrics,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->resolve($this->query($request, 'as_of'), $principal->tenantId())->value();

        return ApiResponse::ok($this->dashboard->dashboard($principal, $asOf));
    }

    public function metric(Request $request, string $metric): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->resolve($this->query($request, 'as_of'), $principal->tenantId())->value();
        $filters = [
            'window' => $request->query('window'),
            'org_unit_id' => $request->query('org_unit_id'),
            'min_age_days' => $request->query('min_age_days'),
            'leave_type' => $request->query('leave_type'),
        ];

        return ApiResponse::ok($this->metrics->query($principal, $metric, $asOf, $filters));
    }

    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new \App\Shared\Exceptions\ValidationException([$key => ['The value must be a string.']]);
        }

        return $value;
    }
}
