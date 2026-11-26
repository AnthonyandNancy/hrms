<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Models\StatutoryParameter;
use App\Modules\Payroll\Services\TaxParameterResolver;
use App\Shared\ApiResponse;
use App\Shared\Authorization\PrincipalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PayrollStatutoryParameterController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly TaxParameterResolver $parameters,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->principals->resolve();

        $rows = StatutoryParameter::query()
            ->when(is_string($request->query('parameter_set')), fn ($q) => $q->where('parameter_set', $request->query('parameter_set')))
            ->orderBy('parameter_set')
            ->orderByDesc('effective_from')
            ->orderBy('code')
            ->get();

        $items = $rows->map(static fn (StatutoryParameter $row): array => [
            'id' => (int) $row->id,
            'parameter_set' => (string) $row->parameter_set,
            'code' => (string) $row->code,
            'value_decimal' => $row->value_decimal,
            'value_int' => $row->value_int,
            'effective_from' => (string) $row->effective_from,
            'effective_to' => (string) $row->effective_to,
            'version' => (string) $row->version,
            'approved_by' => (int) $row->approved_by,
            'evidence_ref' => (string) $row->evidence_ref,
            'is_demo' => (bool) $row->is_demo,
        ])->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'parameter_set' => ['required', 'string', 'in:TAX,SOCIAL_INSURANCE,HOUSING_FUND,ROUNDING'],
            'version' => ['required', 'string', 'max:64'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
            'evidence_ref' => ['required', 'string', 'max:255'],
            'is_demo' => ['nullable', 'boolean'],
            'THRESHOLD_CENTS' => ['nullable', 'integer', 'min:0'],
            'MIN_BASE_CENTS' => ['nullable', 'integer', 'min:0'],
            'MAX_BASE_CENTS' => ['nullable', 'integer', 'min:0'],
            'EMPLOYEE_RATE' => ['nullable', 'string'],
            'EMPLOYER_RATE' => ['nullable', 'string'],
            'MODE' => ['nullable', 'string', 'in:HALF_UP,HALF_EVEN,DOWN,UP'],
            'brackets' => ['nullable', 'array'],
            'brackets.*.lower_bound_cents' => ['required_with:brackets', 'integer', 'min:0'],
            'brackets.*.upper_bound_cents' => ['nullable', 'integer'],
            'brackets.*.rate_bps' => ['required_with:brackets', 'integer', 'min:0'],
            'brackets.*.quick_deduction_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        $rows = $this->parameters->createVersion(
            $data,
            $principal->userId(),
            (bool) ($data['is_demo'] ?? false),
            (string) $data['evidence_ref'],
        );

        return ApiResponse::created([
            'version' => (string) $rows[0]->version,
            'parameter_set' => (string) $rows[0]->parameter_set,
            'rows' => count($rows),
            'is_demo' => (bool) $rows[0]->is_demo,
            'demo_notice' => (bool) $rows[0]->is_demo ? 'DEMO values are synthetic and not legal advice.' : null,
        ]);
    }
}
