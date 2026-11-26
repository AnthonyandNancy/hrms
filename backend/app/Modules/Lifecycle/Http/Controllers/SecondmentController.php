<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Lifecycle\Services\SecondmentService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Time\TenantBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SecondmentController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TenantBusinessDate $businessDates,
        private readonly SecondmentService $secondments,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'lifecycle.secondment.manage');

        $data = $request->validate([
            'legal_entity_id' => ['sometimes', 'integer', 'min:1'],
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'as_of' => ['nullable', new BusinessDateRule],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $asOf = isset($data['as_of'])
            ? (string) $data['as_of']
            : $this->businessDates->today($principal->tenantId())->value();

        return $this->projections->ok($this->secondments->project(
            tenantId: $principal->tenantId(),
            legalEntityId: $this->legalEntityId($request, $data),
            employmentId: isset($data['employment_id']) ? (int) $data['employment_id'] : null,
            asOf: $asOf,
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['page_size'] ?? 20),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'lifecycle.secondment.manage');

        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'host_org_unit_id' => ['required', 'integer', 'min:1'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'effective_from' => ['required', new BusinessDateRule],
            'effective_to' => ['required', new BusinessDateRule],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $payload = [
            'employment_id' => (int) $data['employment_id'],
            'host_org_unit_id' => (int) $data['host_org_unit_id'],
            'position_id' => isset($data['position_id']) ? (int) $data['position_id'] : null,
            'effective_from' => (string) $data['effective_from'],
            'effective_to' => (string) $data['effective_to'],
            'reason' => (string) $data['reason'],
        ];

        $definition = new CommandDefinition(
            name: 'lifecycle.secondment.start',
            capability: 'lifecycle.secondment.manage',
            anchor: \App\Shared\Authorization\AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'lifecycle.secondment.started',
        );

        $result = $this->commands->run(
            definition: $definition,
            payload: $payload,
            operation: function () use ($principal, $payload): array {
                $assignmentId = $this->secondments->create(
                    tenantId: $principal->tenantId(),
                    employmentId: (int) $payload['employment_id'],
                    hostOrgUnitId: (int) $payload['host_org_unit_id'],
                    effectiveFrom: (string) $payload['effective_from'],
                    effectiveTo: (string) $payload['effective_to'],
                    reason: (string) $payload['reason'],
                    positionId: $payload['position_id'] !== null ? (int) $payload['position_id'] : null,
                    actorUserId: $principal->userId(),
                );

                $occupant = $this->secondments->find($principal->tenantId(), $assignmentId);

                if ($occupant === null) {
                    throw new NotFoundOrForbiddenException;
                }

                return [
                    'http_status' => 201,
                    'body' => [
                        'assignment_id' => $occupant->assignmentId,
                        'employment_id' => $occupant->employmentId,
                        'employee_no' => $occupant->employeeNo,
                        'person_name' => $occupant->personName,
                        'host_org_unit_id' => $occupant->orgUnitId,
                        'position_id' => $occupant->positionId,
                        'assignment_type' => $occupant->assignmentType,
                        'is_primary' => $occupant->isPrimary,
                        'valid_from' => $occupant->validFrom,
                        'valid_to' => $occupant->validTo,
                    ],
                    'resource_type' => 'assignment',
                    'resource_id' => (string) $occupant->assignmentId,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function legalEntityId(Request $request, array $data): int
    {
        if (isset($data['legal_entity_id'])) {
            return (int) $data['legal_entity_id'];
        }

        $header = $request->header('X-Legal-Entity-Id');

        if (is_numeric($header) && (int) $header > 0) {
            return (int) $header;
        }

        if (isset($data['employment_id'])) {
            $employment = app(\App\Modules\People\Contracts\EmploymentLifecycleDirectory::class)
                ->employment($this->principals->resolve()->tenantId(), (int) $data['employment_id']);

            if ($employment !== null) {
                return $employment->legalEntityId;
            }
        }

        throw new \App\Shared\Exceptions\ValidationException(
            ['legal_entity_id' => ['The legal_entity_id field is required.']],
            'The given data was invalid.',
            ['reason' => 'COMPANY_REQUIRED'],
        );
    }

    private function assertPermits(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today($principal->tenantId())->value();

        // Elevation is checked at the feature level here because the target
        // legal entity is derived from the employment inside the service. The
        // route gate additionally checks the caller's own legal-entity anchor.
        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    private function respond(CommandResult $result): JsonResponse
    {
        $response = $result->status === 201 && ! $result->replayed
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
