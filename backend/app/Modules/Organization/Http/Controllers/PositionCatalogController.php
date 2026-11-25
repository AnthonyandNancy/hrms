<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Services\PositionCatalogService;
use App\Modules\Organization\Services\TenantBusinessDate;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PositionCatalogController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TenantBusinessDate $businessDates,
        private readonly PositionCatalogService $catalog,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
    ) {}

    public function requirements(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $position = $this->position($principal, $id);
        $this->assertPositionPermits($principal, 'org.position.qualification.manage', $position);

        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $asOf = isset($data['as_of'])
            ? (string) $data['as_of']
            : $this->businessDates->today($principal->tenantId())->value();

        return $this->projections->ok($this->catalog->qualificationsProjection(
            tenantId: $principal->tenantId(),
            positionId: (int) $position->id,
            asOf: $asOf,
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['page_size'] ?? 20),
        ));
    }

    public function storeRequirements(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $position = $this->position($principal, $id);
        $this->assertPositionPermits($principal, 'org.position.qualification.manage', $position);

        $data = $request->validate([
            'requirements' => ['required', 'array'],
            'effective_from' => ['required', new BusinessDateRule],
        ]);

        /** @var array<string, mixed> $requirements */
        $requirements = (array) $data['requirements'];
        $payload = [
            'position_id' => (int) $position->id,
            'requirements' => $requirements,
            'effective_from' => (string) $data['effective_from'],
        ];

        $definition = new CommandDefinition(
            name: 'org.position.qualification.version',
            capability: 'org.position.qualification.manage',
            anchor: \App\Shared\Authorization\AnchorType::LEGAL_ENTITY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'org.position.qualification.versioned',
        );

        $result = $this->commands->run(
            definition: $definition,
            payload: $payload,
            operation: function () use ($principal, $payload): array {
                $qualification = $this->catalog->versionQualification(
                    tenantId: $principal->tenantId(),
                    positionId: (int) $payload['position_id'],
                    requirements: (array) $payload['requirements'],
                    effectiveFrom: (string) $payload['effective_from'],
                    actorUserId: $principal->userId(),
                );

                return [
                    'http_status' => 201,
                    'body' => $this->catalog->qualificationPayload($qualification),
                    'resource_type' => 'position_qualification',
                    'resource_id' => (string) $qualification->id,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function grades(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'org.position.grade.manage');

        $data = $request->validate([
            'legal_entity_id' => ['sometimes', 'integer', 'min:1'],
            'as_of' => ['nullable', new BusinessDateRule],
            'active' => ['sometimes', 'in:0,1,true,false'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $asOf = isset($data['as_of'])
            ? (string) $data['as_of']
            : $this->businessDates->today($principal->tenantId())->value();

        return $this->projections->ok($this->catalog->gradesProjection(
            tenantId: $principal->tenantId(),
            legalEntityId: $this->legalEntityId($request, $data),
            asOf: $asOf,
            active: isset($data['active']) ? filter_var($data['active'], FILTER_VALIDATE_BOOLEAN) : null,
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['page_size'] ?? 20),
        ));
    }

    public function storeGrade(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'org.position.grade.manage');

        $data = $request->validate([
            'legal_entity_id' => ['sometimes', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'level' => ['required', 'integer', 'min:0', 'max:9999'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $payload = [
            'legal_entity_id' => $this->legalEntityId($request, $data),
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'level' => (int) $data['level'],
            'active' => (bool) ($data['active'] ?? true),
        ];

        $definition = new CommandDefinition(
            name: 'org.position.grade.upsert',
            capability: 'org.position.grade.manage',
            anchor: \App\Shared\Authorization\AnchorType::LEGAL_ENTITY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'org.position.grade.upserted',
        );

        $result = $this->commands->run(
            definition: $definition,
            payload: $payload,
            operation: function () use ($principal, $payload): array {
                $grade = $this->catalog->upsertGrade(
                    $principal->tenantId(),
                    (int) $payload['legal_entity_id'],
                    $payload,
                    $principal->userId(),
                );
                $created = $grade->wasRecentlyCreated;

                return [
                    'http_status' => $created ? 201 : 200,
                    'body' => $this->catalog->gradePayload($grade),
                    'resource_type' => 'position_grade',
                    'resource_id' => (string) $grade->id,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function staff(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $position = $this->position($principal, $id);
        $this->assertPositionPermits($principal, 'org.position.staff.read', $position);

        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
        ]);
        $asOf = isset($data['as_of'])
            ? (string) $data['as_of']
            : $this->businessDates->today($principal->tenantId())->value();

        return $this->projections->ok($this->catalog->staffProjection(
            tenantId: $principal->tenantId(),
            position: $position,
            asOf: $asOf,
        ));
    }

    /**
     * The legal-entity anchor may arrive as a query/body field or as the
     * standard X-Legal-Entity-Id scoping header; the middleware already used
     * the same value to authorize the route.
     *
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

        throw new \App\Shared\Exceptions\ValidationException(
            ['legal_entity_id' => ['The legal_entity_id field is required.']],
            'The given data was invalid.',
            ['reason' => 'LEGAL_ENTITY_REQUIRED'],
        );
    }

    private function position(Principal $principal, int $id): Position
    {
        $position = $this->catalog->findPosition($principal->tenantId(), $id);

        if (! $position instanceof Position) {
            throw new NotFoundOrForbiddenException;
        }

        return $position;
    }

    /**
     * Position sub-resources have no legal-entity id in the path. The anchor is
     * derived from the position row, never from client input, so a caller
     * without a grant covering that entity gets 403 even with a valid id.
     */
    private function assertPositionPermits(Principal $principal, string $capability, Position $position): void
    {
        $today = $this->businessDates->today($principal->tenantId())->value();

        $allowed = $this->authorizer->allows(
            $principal,
            $capability,
            AnchorRef::legalEntity((int) $position->legal_entity_id),
            $today,
        );

        if (! $allowed && $position->org_unit_id !== null) {
            $orgUnitId = (int) $position->org_unit_id;
            $allowed = $this->authorizer->allows($principal, $capability, AnchorRef::orgUnit($orgUnitId), $today)
                || $this->authorizer->allows($principal, $capability, AnchorRef::orgSubtree($orgUnitId), $today);
        }

        if (! $allowed) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    private function assertPermits(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today($principal->tenantId())->value();

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
