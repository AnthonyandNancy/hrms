<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\PrincipalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PayrollCatalogController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly PayrollRunService $runs,
    ) {}

    public function items(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $legalEntityId = $request->query('legal_entity_id');
        $items = $this->runs->items($principal->tenantId(), is_numeric($legalEntityId) ? (int) $legalEntityId : null);

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function storeItem(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]{1,63}$/'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', 'in:EARNING,DEDUCTION,EMPLOYER_COST,INFO'],
            'taxable' => ['nullable', 'boolean'],
            'insurable' => ['nullable', 'boolean'],
            'calculation' => ['nullable', 'string', 'in:FIXED,ATTENDANCE_PRORATE,PERCENT,FORMULA_LIMITED'],
            'formula_json' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $item = $this->runs->createItem($principal->tenantId(), $principal->userId(), $data);

        return ApiResponse::created([
            'id' => (int) $item->id,
            'code' => (string) $item->code,
            'name' => (string) $item->name,
            'type' => $item->type->value,
            'taxable' => (bool) $item->taxable,
            'insurable' => (bool) $item->insurable,
            'calculation' => (string) $item->calculation,
            'sort_order' => (int) $item->sort_order,
            'status' => (string) $item->status,
        ]);
    }

    public function schemes(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $legalEntityId = $request->query('legal_entity_id');
        $schemes = $this->runs->schemes($principal->tenantId(), is_numeric($legalEntityId) ? (int) $legalEntityId : null);

        return ApiResponse::ok(ApiResponse::list($schemes, 1, max(1, count($schemes)), count($schemes)));
    }

    public function storeScheme(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_-]{1,63}$/'],
            'name' => ['required', 'string', 'max:160'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'version' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
            'proration_divisor' => ['nullable', 'string', 'in:ANNUAL_DAYS_365,ANNUAL_DAYS_360,FIXED_21_75'],
            'rounding_mode' => ['nullable', 'string', 'in:HALF_UP,HALF_EVEN,DOWN,UP'],
            'overtime_multiplier' => ['nullable', 'string', 'regex:/^\d{1,6}(\.\d{1,6})?$/'],
            'allow_negative_net' => ['nullable', 'boolean'],
            'base_salary_cents' => ['nullable', 'integer', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.item_code' => ['required_with:items', 'string', 'max:64'],
            'items.*.label' => ['nullable', 'string', 'max:160'],
            'items.*.category' => ['nullable', 'string', 'in:EARNING,DEDUCTION,EMPLOYER_COST,INFO'],
            'items.*.amount_cents' => ['nullable', 'integer'],
            'items.*.calculation' => ['nullable', 'string', 'in:FIXED,ATTENDANCE_PRORATE,PERCENT,FORMULA_LIMITED'],
            'items.*.rate_scaled' => ['nullable', 'integer', 'min:0'],
            'items.*.basis' => ['nullable', 'string', 'max:24'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'items.*.taxable' => ['nullable', 'boolean'],
        ]);

        $scheme = $this->runs->createScheme($principal->tenantId(), $principal->userId(), $data);

        return ApiResponse::created([
            'id' => (int) $scheme->id,
            'code' => (string) $scheme->code,
            'name' => (string) $scheme->name,
            'currency' => (string) $scheme->currency,
            'version' => (int) $scheme->version,
            'base_salary_cents' => (int) $scheme->base_salary_cents,
        ]);
    }
}
