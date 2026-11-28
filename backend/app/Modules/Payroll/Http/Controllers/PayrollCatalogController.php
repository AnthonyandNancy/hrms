<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollItemVersion;
use App\Modules\Payroll\Services\PayrollItemService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payroll catalogue reads and effective-dated item commands.
 *
 * Item writes are append-only: POST /items creates identity + version 1,
 * POST /items/{id}/versions appends, POST /items/{id}/retire stamps history.
 * Each write runs through CommandRunner so the Idempotency-Key replay is
 * byte-stable and audit evidence is written inside the same transaction.
 */
final class PayrollCatalogController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly PayrollRunService $runs,
        private readonly PayrollItemService $items,
        private readonly CommandRunner $commands,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    public function items(Request $request): JsonResponse
    {
        $this->principals->resolve();
        $rows = $this->items->items();

        return ApiResponse::ok(ApiResponse::list($rows, 1, max(1, count($rows)), count($rows)));
    }

    public function storeItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]{1,63}$/'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', 'in:EARNING,DEDUCTION,EMPLOYER_COST,INFO'],
            'taxable' => ['nullable', 'boolean'],
            'insurable' => ['nullable', 'boolean'],
            'calculation' => ['nullable', 'string', 'in:FIXED,ATTENDANCE_PRORATE,PERCENT,FORMULA_LIMITED'],
            'formula_json' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'amount_cents' => ['prohibited'],
            'base_salary_cents' => ['prohibited'],
            'effective_from' => ['nullable', new BusinessDateRule],
            'effective_to' => ['nullable', new BusinessDateRule],
        ]);

        $payload = [
            'code' => strtoupper((string) $data['code']),
            'name' => (string) $data['name'],
            'type' => (string) $data['type'],
            'taxable' => (bool) ($data['taxable'] ?? true),
            'insurable' => (bool) ($data['insurable'] ?? true),
            'calculation' => (string) ($data['calculation'] ?? 'FIXED'),
            'formula_json' => $data['formula_json'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'effective_from' => (string) ($data['effective_from'] ?? $this->businessDates->today()->value()),
            'effective_to' => $data['effective_to'] ?? null,
        ];

        $result = $this->commands->run(
            $this->definition('payroll.item.create', 'payroll.item.created'),
            $payload,
            function () use ($payload): array {
                $principal = $this->principals->resolve();
                $version = $this->items->create($principal->userId(), $payload);
                $item = PayrollItem::query()->whereKey((int) $version->payroll_item_id)->firstOrFail();

                return [
                    'http_status' => 201,
                    'body' => $this->items->payload($item, $version),
                    'resource_type' => 'payroll_item',
                    'resource_id' => (string) $item->id,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result->status, $result->body, $result->replayed);
    }

    public function storeVersion(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'type' => ['nullable', 'string', 'in:EARNING,DEDUCTION,EMPLOYER_COST,INFO'],
            'taxable' => ['nullable', 'boolean'],
            'insurable' => ['nullable', 'boolean'],
            'calculation' => ['nullable', 'string', 'in:FIXED,ATTENDANCE_PRORATE,PERCENT,FORMULA_LIMITED'],
            'formula_json' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'effective_from' => ['required', new BusinessDateRule],
            'effective_to' => ['nullable', new BusinessDateRule],
            // Server-owned field: clients may send it, but it is ignored.
            'version' => ['nullable', 'integer'],
            'amount_cents' => ['prohibited'],
            'base_salary_cents' => ['prohibited'],
        ]);

        $payload = [
            'item_id' => $id,
            'name' => $data['name'] ?? null,
            'type' => $data['type'] ?? null,
            'taxable' => $data['taxable'] ?? null,
            'insurable' => $data['insurable'] ?? null,
            'calculation' => $data['calculation'] ?? null,
            'formula_json' => $data['formula_json'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
            'effective_from' => (string) $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
        ];

        $result = $this->commands->run(
            $this->definition('payroll.item.version', 'payroll.item.versioned'),
            $payload,
            function () use ($payload, $id): array {
                $principal = $this->principals->resolve();
                $version = $this->items->appendVersion($principal->userId(), $id, $payload);
                $item = PayrollItem::query()->whereKey($id)->firstOrFail();

                return [
                    'http_status' => 201,
                    'body' => $this->items->payload($item, $version),
                    'resource_type' => 'payroll_item_version',
                    'resource_id' => (string) $version->id,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result->status, $result->body, $result->replayed);
    }

    public function retire(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $payload = [
            'item_id' => $id,
            'reason' => (string) $data['reason'],
        ];

        $result = $this->commands->run(
            $this->definition('payroll.item.retire', 'payroll.item.retired'),
            $payload,
            function () use ($id, $payload): array {
                $principal = $this->principals->resolve();
                $item = $this->items->retire($principal->userId(), $id, (string) $payload['reason']);
                $current = PayrollItemVersion::query()
                    ->where('payroll_item_id', (int) $item->id)
                    ->orderByDesc('version')
                    ->firstOrFail();

                return [
                    'http_status' => 200,
                    'body' => $this->items->payload($item, $current),
                    'resource_type' => 'payroll_item',
                    'resource_id' => (string) $item->id,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result->status, $result->body, $result->replayed);
    }

    public function schemes(Request $request): JsonResponse
    {
        $this->principals->resolve();
        $schemes = $this->runs->schemes();

        return ApiResponse::ok(ApiResponse::list($schemes, 1, max(1, count($schemes)), count($schemes)));
    }

    public function storeScheme(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_-]{1,63}$/'],
            'name' => ['required', 'string', 'max:160'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            // Kept for backward compatibility, but the server always allocates
            // the next version under a row lock.
            'version' => ['nullable', 'integer'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
            'proration_divisor' => ['nullable', 'string', 'in:ANNUAL_DAYS_365,ANNUAL_DAYS_360,FIXED_21_75'],
            'rounding_mode' => ['nullable', 'string', 'in:HALF_UP,HALF_EVEN,DOWN,UP'],
            'overtime_multiplier' => ['nullable', 'string', 'regex:/^\d{1,6}(\.\d{6,18})?$/'],
            'allow_negative_net' => ['nullable', 'boolean'],
            'base_salary_cents' => ['nullable', 'integer', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.item_code' => ['required_with:items', 'string', 'max:64'],
            'items.*.payroll_item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.label' => ['nullable', 'string', 'max:160'],
            'items.*.category' => ['nullable', 'string', 'in:EARNING,DEDUCTION,EMPLOYER_COST,INFO'],
            'items.*.amount_cents' => ['nullable', 'integer'],
            'items.*.calculation' => ['nullable', 'string', 'in:FIXED,ATTENDANCE_PRORATE,PERCENT,FORMULA_LIMITED'],
            'items.*.rate_scaled' => ['nullable', 'integer', 'min:0'],
            'items.*.rate' => ['nullable', 'string', 'regex:/^\d{1,6}(\.\d{1,6})?$/'],
            'items.*.basis' => ['nullable', 'string', 'max:24'],
            'items.*.formula' => ['nullable', 'array'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'items.*.taxable' => ['nullable', 'boolean'],
        ]);

        $scheme = $this->runs->createScheme($principal->userId(), $data);

        return ApiResponse::created([
            'id' => (int) $scheme->id,
            'code' => (string) $scheme->code,
            'name' => (string) $scheme->name,
            'currency' => (string) $scheme->currency,
            'version' => (int) $scheme->version,
            'base_salary_cents' => (int) $scheme->base_salary_cents,
        ]);
    }

    private function definition(string $name, string $auditAction): CommandDefinition
    {
        return new CommandDefinition(
            name: $name,
            capability: 'payroll.run.prepare',
            anchor: \App\Shared\Authorization\AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: $auditAction,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function respond(int $status, array $body, bool $replayed): JsonResponse
    {
        $response = $status === 201 && ! $replayed
            ? ApiResponse::created($body)
            : ApiResponse::ok($body);

        if ($replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
