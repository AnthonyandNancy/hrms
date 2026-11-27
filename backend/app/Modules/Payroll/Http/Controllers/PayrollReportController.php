<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Services\PayrollReportService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aggregate-only payroll report read surface. No employee-level wage fields are
 * returned and no exports are produced here; exports continue to use the
 * run-scoped, audited /exports endpoints.
 */
final class PayrollReportController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly PayrollReportService $reports,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $this->principals->resolve();

        $data = $request->validate([
            'period' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'from' => ['nullable', new BusinessDateRule],
            'to' => ['nullable', new BusinessDateRule],
            'group_by' => ['nullable', 'string', 'in:period,department'],
        ]);

        $orders = array_values(array_filter([
            isset($data['from']) ? 'from' : null,
            isset($data['to']) ? 'to' : null,
        ]));

        if (in_array('from', $orders, true) && in_array('to', $orders, true)
            && (string) $data['from'] > (string) $data['to']) {
            throw new ValidationException([
                'from' => ['The from date must not be after the to date.'],
            ]);
        }

        return ApiResponse::ok($this->reports->summary([
            'period' => isset($data['period']) ? (string) $data['period'] : null,
            'from' => isset($data['from']) ? (string) $data['from'] : null,
            'to' => isset($data['to']) ? (string) $data['to'] : null,
            'group_by' => isset($data['group_by']) ? (string) $data['group_by'] : 'period',
        ]));
    }
}
