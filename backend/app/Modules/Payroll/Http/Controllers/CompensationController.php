<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Payroll\Models\EmployeeCompensation;
use App\Modules\Payroll\Models\SalaryAdjustment;
use App\Modules\Payroll\Services\CompensationService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Authorization\ResourceRef;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\TenantBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Effective-dated compensation archive and case-approved salary adjustments.
 *
 * Reads go through the ProjectionResponder envelope and resolve the P3 field
 * set per row (which records sensitive read evidence in production). Writes go
 * through CommandRunner so every mutation is idempotent, and the service
 * records P3 write evidence with field names only.
 */
final class CompensationController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TenantBusinessDate $businessDates,
        private readonly CompensationService $compensations,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
    ) {}

    public function plans(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $businessDate = $this->businessDates->today($principal->tenantId())->value();
        [$page, $pageSize] = $this->pagination($request);
        $filters = $request->validate([
            'employment_id' => ['nullable', 'integer', 'min:1'],
            'legal_entity_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $result = $this->compensations->listCompensations(
            $principal,
            $businessDate,
            [
                'employment_id' => isset($filters['employment_id']) ? (int) $filters['employment_id'] : null,
                'legal_entity_id' => isset($filters['legal_entity_id']) ? (int) $filters['legal_entity_id'] : null,
            ],
            $page,
            $pageSize,
        );

        $items = $result['rows']->map(fn (EmployeeCompensation $row): array => $this->planRow($principal, $row))->all();

        return $this->projections->ok(new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $result['total'],
            asOf: $businessDate,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: '',
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'employee_compensations'],
        ));
    }

    public function storePlan(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', new BusinessDateRule],
            'base_salary_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'salary_scheme_code' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'payroll.compensation.version.create',
                capability: 'payroll.compensation.manage',
                anchor: AnchorType::LEGAL_ENTITY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'payroll.compensation.versioned',
            ),
            $data,
            function () use ($principal, $data): array {
                $row = $this->compensations->createCompensation($principal->tenantId(), $principal->userId(), $data);

                return [
                    'http_status' => 201,
                    'body' => ApiResponse::created($this->planRow($principal, $row, forWrite: true))->getData(true),
                    'resource_type' => 'employee_compensation',
                    'resource_id' => (string) $row->id,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->commandResponse($result->body, $result->status, $result->replayed);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $businessDate = $this->businessDates->today($principal->tenantId())->value();
        [$page, $pageSize] = $this->pagination($request);
        $filters = $request->validate([
            'employment_id' => ['nullable', 'integer', 'min:1'],
            'legal_entity_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $result = $this->compensations->listAdjustments(
            $principal,
            $businessDate,
            [
                'employment_id' => isset($filters['employment_id']) ? (int) $filters['employment_id'] : null,
                'legal_entity_id' => isset($filters['legal_entity_id']) ? (int) $filters['legal_entity_id'] : null,
            ],
            $page,
            $pageSize,
        );

        $items = $result['rows']->map(fn (SalaryAdjustment $row): array => $this->adjustmentRow($principal, $row))->all();

        return $this->projections->ok(new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $result['total'],
            asOf: $businessDate,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: '',
            partial: false,
            partialReason: null,
            drilldown: ['resource' => 'salary_adjustments'],
        ));
    }

    public function storeAdjustment(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', new BusinessDateRule],
            'base_salary_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'reason' => ['required', 'string', 'max:500'],
            'case_id' => ['required', 'integer', 'min:1'],
            'salary_scheme_code' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'payroll.salary_adjustment.create',
                capability: 'payroll.salary-adjustment.manage',
                anchor: AnchorType::LEGAL_ENTITY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'payroll.salary_adjustment.created',
            ),
            $data,
            function () use ($principal, $data): array {
                $created = $this->compensations->createAdjustment($principal->tenantId(), $principal->userId(), $data);
                $row = $created['adjustment'];

                return [
                    'http_status' => 201,
                    'body' => ApiResponse::created($this->adjustmentRow($principal, $row, forWrite: true))->getData(true),
                    'resource_type' => 'salary_adjustment',
                    'resource_id' => (string) $row->id,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->commandResponse($result->body, $result->status, $result->replayed);
    }

    /**
     * @return array<string, mixed>
     */
    private function planRow(Principal $principal, EmployeeCompensation $row, bool $forWrite = false): array
    {
        // A write response echoes the amount the caller just supplied; only
        // reads resolve the P3 field set (and record sensitive-read evidence).
        $fields = $forWrite ? null : $this->fieldsFor($principal, $row);

        return [
            'id' => (int) $row->id,
            'employment_id' => (int) $row->employment_id,
            'legal_entity_id' => (int) $row->legal_entity_id,
            'effective_from' => (string) $row->effective_from,
            'currency' => (string) $row->currency,
            'salary_scheme_code' => $row->salary_scheme_code !== null ? (string) $row->salary_scheme_code : null,
            'created_by' => (int) $row->created_by,
            'created_at' => $row->created_at?->toIso8601ZuluString(),
            ...($forWrite || ($fields !== null && $fields->isVisible('base_salary_cents')) ? ['base_salary_cents' => (int) $row->base_salary_cents] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adjustmentRow(Principal $principal, SalaryAdjustment $row, bool $forWrite = false): array
    {
        $fields = $forWrite ? null : $this->fieldsFor($principal, $row);

        return [
            'id' => (int) $row->id,
            'employment_id' => (int) $row->employment_id,
            'legal_entity_id' => (int) $row->legal_entity_id,
            'effective_from' => (string) $row->effective_from,
            'currency' => (string) $row->currency,
            'case_id' => (int) $row->case_id,
            'compensation_id' => (int) $row->compensation_id,
            'reason' => (string) $row->reason,
            'created_by' => (int) $row->created_by,
            'created_at' => $row->created_at?->toIso8601ZuluString(),
            ...($forWrite || ($fields !== null && $fields->isVisible('base_salary_cents')) ? ['base_salary_cents' => (int) $row->base_salary_cents] : []),
        ];
    }

    private function fieldsFor(Principal $principal, EmployeeCompensation|SalaryAdjustment $row): FieldSet
    {
        $resourceType = $row instanceof EmployeeCompensation ? 'employee_compensation' : 'salary_adjustment';

        return $this->authorizer->fields(
            $principal,
            'payroll.compensation.read',
            new ResourceRef(
                resourceType: $resourceType,
                id: (int) $row->id,
                row: [],
                tenantId: (int) $row->tenant_id,
                employmentId: (int) $row->employment_id,
                legalEntityId: (int) $row->legal_entity_id,
                caseId: $row instanceof SalaryAdjustment ? (int) $row->case_id : null,
            ),
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pagination(Request $request): array
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return [
            isset($data['page']) ? (int) $data['page'] : 1,
            isset($data['page_size']) ? (int) $data['page_size'] : 20,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function commandResponse(array $body, int $status, bool $replayed): JsonResponse
    {
        $response = new JsonResponse($body, $status);

        if ($replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
