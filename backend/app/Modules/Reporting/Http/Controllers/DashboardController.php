<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Services\DashboardService;
use App\Modules\Reporting\Services\MetricDefinition;
use App\Modules\Reporting\Services\MetricQueryBuilder;
use App\Shared\ApiResponse;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly CompanyBusinessDate $businessDates,
        private readonly DashboardService $dashboard,
        private readonly MetricQueryBuilder $metrics,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->resolve($this->query($request, 'as_of'))->value();

        return ApiResponse::ok($this->dashboard->dashboard($principal, $asOf));
    }

    public function metric(Request $request, string $metric): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->resolve($this->query($request, 'as_of'))->value();

        if (! MetricDefinition::exists($metric)) {
            throw new ValidationException(['metric' => ['Unknown dashboard metric.']], 'The given data was invalid.', ['reason' => 'UNKNOWN_METRIC']);
        }

        $filters = [
            'window' => $this->query($request, 'window'),
            'org_unit_id' => $this->query($request, 'org_unit_id'),
            'min_age_days' => $this->query($request, 'min_age_days'),
            'leave_type' => $this->query($request, 'leave_type'),
        ];

        // Dashboard drill-downs are the canonical metric read: metric-specific
        // payroll/recruitment field gates apply in addition to the route gate.
        return ApiResponse::ok($this->metrics->query($principal, $metric, $asOf, $filters));
    }

    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new ValidationException([$key => ['The value must be a string.']]);
        }

        return $value;
    }
}
