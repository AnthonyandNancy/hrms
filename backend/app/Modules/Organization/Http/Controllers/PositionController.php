<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Services\PositionService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PositionController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly PositionService $positions,
        private readonly CommandRunner $commands,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'org.position.read');

        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'q' => ['nullable', 'string', 'max:160'],
            'org_unit_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:ACTIVE,DISABLED'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:code,code_desc,title,level'],
        ]);
        $on = isset($data['as_of'])
            ? (string) $data['as_of']
            : $this->businessDates->today()->value();

        $result = $this->positions->paginate(
            principal: $principal,
            on: $on,
            filters: [
                'q' => $data['q'] ?? null,
                'org_unit_id' => isset($data['org_unit_id']) ? (int) $data['org_unit_id'] : null,
                'status' => $data['status'] ?? null,
                'sort' => $data['sort'] ?? 'code',
            ],
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['page_size'] ?? 20),
        );

        return ApiResponse::ok([
            ...ApiResponse::list($result['items'], $result['page'], $result['page_size'], $result['total']),
            'partial' => false,
            'partial_reason' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'org.position.manage');

        $data = $request->validate([
            'org_unit_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'],
            'grade' => ['nullable', 'string', 'max:32'],
            'headcount_limit' => ['nullable', 'integer', 'min:0'],
            'valid_from' => ['nullable', new BusinessDateRule],
            'valid_to' => ['nullable', new BusinessDateRule],
        ]);

        if (isset($data['org_unit_id'])) {
            $this->assertManagesOrgUnit($principal, (int) $data['org_unit_id']);
        }

        $payload = [
            'org_unit_id' => isset($data['org_unit_id']) ? (int) $data['org_unit_id'] : null,
            'code' => trim((string) $data['code']),
            'title' => trim((string) $data['title']),
            'grade' => isset($data['grade']) && (string) $data['grade'] !== '' ? (string) $data['grade'] : null,
            'headcount_limit' => isset($data['headcount_limit']) ? (int) $data['headcount_limit'] : null,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
        ];

        $result = $this->commands->run(
            definition: $this->definition(
                name: 'org.position.create',
                auditAction: 'org.position.created',
            ),
            payload: $payload,
            operation: function () use ($principal, $payload): array {
                $position = $this->positions->open($payload, $principal->userId());

                return [
                    'http_status' => 201,
                    'body' => $this->positions->payloads(
                        [$position],
                        $this->businessDates->today()->value(),
                    )[0],
                    'resource_type' => 'position',
                    'resource_id' => (string) $position->id,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'org.position.manage');

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'grade' => ['sometimes', 'nullable', 'string', 'max:32'],
            'headcount_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,DISABLED'],
        ]);

        $payload = ['position_id' => $id];

        foreach (['title', 'grade', 'headcount_limit', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (array_key_exists('title', $payload)) {
            $payload['title'] = trim((string) $payload['title']);
        }

        $result = $this->commands->run(
            definition: $this->definition(
                name: 'org.position.update',
                auditAction: 'org.position.updated',
            ),
            payload: $payload,
            operation: function () use ($principal, $id, $payload): array {
                $position = $this->positions->findVisible(
                    $principal,
                    $id,
                    $this->businessDates->today()->value(),
                    'org.position.manage',
                );
                $position = $this->positions->revise((int) $position->id, $payload, $principal->userId());

                return [
                    'http_status' => 200,
                    'body' => $this->positions->payloads(
                        [$position],
                        $this->businessDates->today()->value(),
                    )[0],
                    'resource_type' => 'position',
                    'resource_id' => (string) $position->id,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertPermits($principal, 'org.position.read');

        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
        ]);
        $on = isset($data['as_of'])
            ? (string) $data['as_of']
            : $this->businessDates->today()->value();

        $position = $this->positions->findVisible($principal, $id, $on);

        return ApiResponse::ok($this->positions->payloads([$position], $on)[0]);
    }

    private function definition(string $name, string $auditAction): CommandDefinition
    {
        return new CommandDefinition(
            name: $name,
            capability: 'org.position.manage',
            anchor: \App\Shared\Authorization\AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: $auditAction,
        );
    }

    private function assertPermits(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * A position belongs to one organization unit. A manager scoped to unit A
     * must not be able to place a new position under unit B by supplying an id.
     */
    private function assertManagesOrgUnit(Principal $principal, int $orgUnitId): void
    {
        $today = $this->businessDates->today()->value();

        $allowed = $this->authorizer->allows($principal, 'org.position.manage', \App\Shared\Authorization\AnchorRef::company(), $today)
            || $this->authorizer->allows($principal, 'org.position.manage', \App\Shared\Authorization\AnchorRef::orgUnit($orgUnitId), $today)
            || $this->authorizer->allows($principal, 'org.position.manage', \App\Shared\Authorization\AnchorRef::orgSubtree($orgUnitId), $today);

        if (! $allowed) {
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
