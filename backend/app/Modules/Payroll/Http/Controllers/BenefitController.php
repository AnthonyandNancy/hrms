<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Models\BenefitEnrollment;
use App\Modules\Payroll\Models\BenefitPlan;
use App\Modules\Payroll\Models\BenefitTransaction;
use App\Modules\Payroll\Services\BenefitService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Time\TenantBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Benefits and statutory bases. Reads use ProjectionResponder; every write is
 * a CommandRunner command with a required Idempotency-Key.
 */
final class BenefitController
{
    public function __construct(
        private readonly \App\Shared\Authorization\PrincipalResolver $principals,
        private readonly TenantBusinessDate $businessDates,
        private readonly BenefitService $benefits,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
    ) {}

    public function plans(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->resolve(
            $request->query('as_of') !== null ? (string) $request->query('as_of') : null,
            $principal->tenantId(),
        )->value();
        $data = $request->validate([
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'legal_entity_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $result = $this->benefits->listPlans(
            $principal,
            [
                'code' => isset($data['code']) ? (string) $data['code'] : null,
                'legal_entity_id' => isset($data['legal_entity_id']) ? (int) $data['legal_entity_id'] : null,
            ],
            $asOf,
            $page,
            $pageSize,
        );
        $items = $result['rows']->map(fn (BenefitPlan $plan): array => $this->benefits->planPayload($plan))->all();

        return $this->projections->ok($this->benefits->projection($asOf, $items, $result['total'], $page, $pageSize, 'benefit_plans'));
    }

    public function storePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Z0-9_]{2,63}$/'],
            'name' => ['required', 'string', 'max:160'],
            'employer_rate' => ['required', 'string', 'max:32'],
            'employee_rate' => ['required', 'string', 'max:32'],
            'effective_from' => ['required', new BusinessDateRule],
            'effective_to' => ['nullable', new BusinessDateRule],
            'legal_entity_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'payroll.benefit.plan.version.create',
                capability: 'benefit.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'benefit.plan.versioned',
            ),
            $data,
            function () use ($data): array {
                $principal = $this->principals->resolve();
                $plan = $this->benefits->createPlan($principal->tenantId(), $principal->userId(), $data);

                return [
                    'http_status' => 201,
                    'body' => $this->benefits->planPayload($plan),
                    'resource_type' => 'benefit_plan',
                    'resource_id' => (string) $plan->id,
                ];
            },
            $this->idempotencyKey($request),
        );

        return $this->commandResponse($result->status, $result->body, $result->replayed);
    }

    public function enrollments(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->today($principal->tenantId())->value();
        $data = $request->validate([
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'plan_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $result = $this->benefits->listEnrollments(
            $principal->tenantId(),
            [
                'employment_id' => isset($data['employment_id']) ? (int) $data['employment_id'] : null,
                'plan_id' => isset($data['plan_id']) ? (int) $data['plan_id'] : null,
            ],
            $page,
            $pageSize,
        );
        $plans = BenefitPlan::query()
            ->where('tenant_id', $principal->tenantId())
            ->whereIn('id', $result['rows']->pluck('plan_id')->all())
            ->get()
            ->keyBy('id');
        $items = $result['rows']->map(function (BenefitEnrollment $enrollment) use ($plans): array {
            /** @var BenefitPlan $plan */
            $plan = $plans->get((int) $enrollment->plan_id);

            return $this->benefits->enrollmentPayload($enrollment, $plan);
        })->all();

        return $this->projections->ok($this->benefits->projection($asOf, $items, $result['total'], $page, $pageSize, 'benefit_enrollments'));
    }

    public function storeEnrollment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'min:1'],
            'employment_id' => ['required', 'integer', 'min:1'],
            'base_amount_cents' => ['required', 'integer', 'min:0'],
            'effective_from' => ['required', new BusinessDateRule],
            'effective_to' => ['nullable', new BusinessDateRule],
        ]);

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'payroll.benefit.enrollment.version.create',
                capability: 'benefit.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'benefit.enrollment.versioned',
            ),
            $data,
            function () use ($data): array {
                $principal = $this->principals->resolve();
                $enrollment = $this->benefits->createEnrollment($principal, $data);
                $plan = BenefitPlan::query()
                    ->where('tenant_id', $principal->tenantId())
                    ->whereKey((int) $enrollment->plan_id)
                    ->firstOrFail();

                return [
                    'http_status' => 201,
                    'body' => $this->benefits->enrollmentPayload($enrollment, $plan),
                    'resource_type' => 'benefit_enrollment',
                    'resource_id' => (string) $enrollment->id,
                ];
            },
            $this->idempotencyKey($request),
        );

        return $this->commandResponse($result->status, $result->body, $result->replayed);
    }

    public function transactions(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->businessDates->today($principal->tenantId())->value();
        $data = $request->validate([
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'enrollment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'payroll_run_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'period' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $pageSize = (int) ($data['page_size'] ?? 20);
        $result = $this->benefits->listTransactions(
            $principal->tenantId(),
            [
                'employment_id' => isset($data['employment_id']) ? (int) $data['employment_id'] : null,
                'enrollment_id' => isset($data['enrollment_id']) ? (int) $data['enrollment_id'] : null,
                'payroll_run_id' => isset($data['payroll_run_id']) ? (int) $data['payroll_run_id'] : null,
                'period' => isset($data['period']) ? (string) $data['period'] : null,
            ],
            $page,
            $pageSize,
        );
        $items = $result['rows']->map(fn (BenefitTransaction $row): array => $this->benefits->transactionPayload($row))->all();

        return $this->projections->ok($this->benefits->projection($asOf, $items, $result['total'], $page, $pageSize, 'benefit_transactions'));
    }

    public function storeTransaction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_id' => ['required', 'integer', 'min:1'],
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'payroll_run_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'payroll.benefit.transaction.record',
                capability: 'benefit.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'benefit.transaction.recorded',
            ),
            $data,
            function () use ($data): array {
                $principal = $this->principals->resolve();
                $transaction = $this->benefits->createTransaction($principal->tenantId(), $principal->userId(), $data);

                return [
                    'http_status' => 201,
                    'body' => $this->benefits->transactionPayload($transaction),
                    'resource_type' => 'benefit_transaction',
                    'resource_id' => (string) $transaction->id,
                ];
            },
            $this->idempotencyKey($request),
        );

        return $this->commandResponse($result->status, $result->body, $result->replayed);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key)) {
            return '';
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function commandResponse(int $status, array $body, bool $replayed): JsonResponse
    {
        $response = ApiResponse::ok($body, $status);

        if ($replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
