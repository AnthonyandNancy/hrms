<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Services\DepartmentStaffingService;
use App\Modules\Organization\Services\OrgUnitService;
use App\Modules\People\Contracts\DepartmentMembershipPort;
use App\Modules\People\Contracts\DepartmentStaffingDirectory;
use App\Shared\ApiResponse;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Validation\BusinessDateRule;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DepartmentController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly CompanyBusinessDate $businessDates,
        private readonly DepartmentStaffingDirectory $staffing,
        private readonly DepartmentStaffingService $staffingWriter,
        private readonly DepartmentMembershipPort $membership,
        private readonly CommandRunner $commands,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'org.unit.read');
        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'q' => ['nullable', 'string', 'max:160'],
        ]);
        $on = $data['as_of'] ?? $this->businessDates->today()->value();

        $query = OrgUnit::query()
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderBy('code');

        if (isset($data['q']) && $data['q'] !== '') {
            $query->where(function ($builder) use ($data): void {
                $builder->where('code', 'like', '%'.$data['q'].'%')
                    ->orWhere('name', 'like', '%'.$data['q'].'%');
            });
        }

        $units = $query->get();
        $ids = $units->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $headcount = $this->staffing->activeHeadcount($ids, $on);
        $limits = $this->headcountLimits($ids, $on);

        $items = $units->map(fn (OrgUnit $unit): array => $this->payload(
            $unit,
            $headcount[(int) $unit->id] ?? 0,
            $limits[(int) $unit->id] ?? null,
        ))->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function members(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'org.unit.read');
        $this->assertUnit($id);
        $data = $request->validate(['as_of' => ['nullable', new BusinessDateRule]]);
        $on = $data['as_of'] ?? $this->businessDates->today()->value();

        $items = $this->staffing->members($id, $on);

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function addMember(Request $request, int $id): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'org.unit.manage');
        $this->assertUnit($id);

        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', new BusinessDateRule],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $result = $this->runCommand(
            request: $request,
            definition: new CommandDefinition(
                name: 'org.department.member.move',
                capability: 'org.unit.manage',
                anchor: \App\Shared\Authorization\AnchorType::ORG_UNIT,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.department.member_moved',
            ),
            payload: [
                'org_unit_id' => $id,
                'employment_id' => (int) $data['employment_id'],
                'effective_from' => (string) $data['effective_from'],
            ],
            operation: fn (): array => [
                'http_status' => 201,
                'body' => [
                    ...$this->membership->moveMember(
                        $id,
                        (int) $data['employment_id'],
                        (string) $data['effective_from'],
                        $principal->userId(),
                    ),
                    'reason' => (string) $data['reason'],
                ],
                'resource_type' => 'assignment',
            ],
        );

        return $this->respond($result);
    }

    public function staffing(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'org.unit.read');
        $data = $request->validate([
            'as_of' => ['nullable', new BusinessDateRule],
            'org_unit_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $on = $data['as_of'] ?? $this->businessDates->today()->value();
        $orgUnitId = isset($data['org_unit_id']) ? (int) $data['org_unit_id'] : null;

        $items = array_map(
            static fn ($snapshot): array => $snapshot->toArray(),
            $this->staffingWriter->snapshot($on, $orgUnitId),
        );

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function store(Request $request, OrgUnitService $units): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'org.unit.manage');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['required', 'string', 'in:COMPANY,DIVISION,DEPARTMENT,TEAM,COST_CENTER,SHARED_SERVICE'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
            'manager_employment_id' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['required', new BusinessDateRule],
            'valid_to' => ['nullable', new BusinessDateRule],
        ]);

        $payload = [
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'type' => (string) $data['type'],
            'status' => (string) ($data['status'] ?? 'ACTIVE'),
            'manager_employment_id' => $data['manager_employment_id'] ?? null,
            'valid_from' => (string) $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
        ];

        $result = $this->runCommand(
            request: $request,
            definition: new CommandDefinition(
                name: 'org.department.create',
                capability: 'org.unit.manage',
                anchor: \App\Shared\Authorization\AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'org.unit.created',
            ),
            payload: $payload,
            operation: fn (): array => [
                'http_status' => 201,
                'body' => $this->payload(
                    $units->open($payload, $principal->userId()),
                    null,
                    null,
                ),
                'resource_type' => 'org_unit',
            ],
        );

        return $this->respond($result);
    }

    /**
     * Basic facts and a controlled hierarchy move share one department write
     * entry. Sending `new_parent_id`/`effective_from` performs a move; basic
     * fields alone go through revise(). Both paths run through CommandRunner.
     * Headcount remains an independent versioned fact and is not accepted here.
     */
    public function update(Request $request, int $id, OrgUnitService $units): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'org.unit.manage');
        $this->assertUnit($id);

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:160'],
            'type' => ['sometimes', 'string', 'in:COMPANY,DIVISION,DEPARTMENT,TEAM,COST_CENTER,SHARED_SERVICE'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,INACTIVE'],
            'valid_from' => ['sometimes', new BusinessDateRule],
            'valid_to' => ['sometimes', 'nullable', new BusinessDateRule],
            'manager_employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'new_parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'effective_from' => ['sometimes', new BusinessDateRule],
            'headcount_limit' => ['sometimes', 'integer', 'min:0'],
            'headcount_effective_from' => ['sometimes', new BusinessDateRule],
        ]);

        $isMove = array_key_exists('new_parent_id', $data) || array_key_exists('effective_from', $data);

        if (array_key_exists('headcount_limit', $data) && ($isMove || array_key_exists('manager_employment_id', $data) || array_key_exists('code', $data) || array_key_exists('name', $data) || array_key_exists('type', $data) || array_key_exists('status', $data))) {
            throw new ValidationException(
                ['headcount_limit' => ['Headcount versions must be submitted as a separate command payload.']],
                'Headcount versions must be submitted as a separate command payload.',
            );
        }

        if ($isMove && ! array_key_exists('new_parent_id', $data)) {
            throw new ValidationException(
                ['new_parent_id' => ['A hierarchy move requires both new_parent_id and effective_from.']],
                'A hierarchy move requires both new_parent_id and effective_from.',
            );
        }

        if ($isMove && ! array_key_exists('effective_from', $data)) {
            throw new ValidationException(
                ['effective_from' => ['A hierarchy move requires both new_parent_id and effective_from.']],
                'A hierarchy move requires both new_parent_id and effective_from.',
            );
        }

        $basicFields = array_intersect_key($data, array_flip([
            'code', 'name', 'type', 'status', 'manager_employment_id', 'valid_from', 'valid_to',
        ]));

        if ($basicFields === [] && ! $isMove && ! array_key_exists('headcount_limit', $data)) {
            throw new ValidationException(
                ['code' => ['At least one department field is required.']],
                'At least one department field is required.',
            );
        }

        if (array_key_exists('headcount_limit', $data)) {
            if (! array_key_exists('headcount_effective_from', $data)) {
                throw new ValidationException(
                    ['headcount_effective_from' => ['A headcount version requires headcount_effective_from.']],
                    'A headcount version requires headcount_effective_from.',
                );
            }

            return $this->updateHeadcount($request, $id, $principal, (int) $data['headcount_limit'], $data);
        }

        $commandName = $isMove ? 'org.department.move' : 'org.department.update';
        $payload = $isMove
            ? [
                'org_unit_id' => $id,
                'new_parent_id' => $data['new_parent_id'] ?? null,
                'effective_from' => (string) $data['effective_from'],
            ]
            : ['org_unit_id' => $id, ...$basicFields];

        $result = $this->runCommand(
            request: $request,
            definition: new CommandDefinition(
                name: $commandName,
                capability: 'org.unit.manage',
                anchor: \App\Shared\Authorization\AnchorType::ORG_UNIT,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: $isMove ? 'org.unit.moved' : 'org.unit.updated',
            ),
            payload: $payload,
            operation: function () use ($isMove, $basicFields, $id, $data, $principal, $units): array {
                if ($isMove) {
                    $result = $units->move($id, [
                        'new_parent_id' => $data['new_parent_id'] ?? null,
                        'effective_from' => (string) $data['effective_from'],
                    ], $principal->userId());

                    return [
                        'http_status' => 200,
                        'body' => [
                            'moved' => true,
                            'old_org_unit_id' => $result['old_id'],
                            'org_unit' => $this->payload($result['org_unit'], null, null),
                        ],
                        'resource_type' => 'org_unit',
                        'resource_id' => (string) $id,
                    ];
                }

                $unit = $units->revise($id, $basicFields, $principal->userId());

                return [
                    'http_status' => 200,
                    'body' => [
                        'moved' => false,
                        'old_org_unit_id' => null,
                        'org_unit' => $this->payload($unit, null, null),
                    ],
                    'resource_type' => 'org_unit',
                    'resource_id' => (string) $id,
                ];
            },
        );

        return $this->respond($result);
    }

    /**
     * Versioned headcount lives on the existing department PATCH command so the
     * API can be closed without adding a route outside the M05 file boundary.
     *
     * @param  array<string, mixed>  $data
     */
    private function updateHeadcount(Request $request, int $id, Principal $principal, int $limit, array $data): JsonResponse
    {
        $service = $this->staffingWriter;
        $effectiveFrom = isset($data['headcount_effective_from'])
            ? (string) $data['headcount_effective_from']
            : (isset($data['effective_from']) ? (string) $data['effective_from'] : $this->businessDates->today()->value());

        $payload = [
            'org_unit_id' => $id,
            'headcount_limit' => $limit,
            'effective_from' => $effectiveFrom,
        ];

        $result = $this->runCommand(
            request: $request,
            definition: new CommandDefinition(
                name: 'org.department.headcount.version',
                capability: 'org.unit.manage',
                anchor: \App\Shared\Authorization\AnchorType::ORG_UNIT,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'org.department.headcount.versioned',
            ),
            payload: $payload,
            operation: function () use ($service, $id, $limit, $effectiveFrom, $principal): array {
                $version = $service->publish($id, $limit, $effectiveFrom, $principal->userId());

                return [
                    'http_status' => 201,
                    'body' => [
                        'org_unit_id' => $id,
                        'headcount_limit' => (int) $version->headcount_limit,
                        'effective_from' => (string) $version->effective_from,
                        'version' => (int) $version->id,
                    ],
                    'resource_type' => 'org_unit_headcount_version',
                    'resource_id' => (string) $version->id,
                ];
            },
        );

        return $this->respond($result);
    }

    public function removeMember(Request $request, int $id, int $employmentId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->assertCapability($principal, 'org.unit.manage');
        $this->assertUnit($id);

        $data = $request->validate([
            'effective_from' => ['required', new BusinessDateRule],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $result = $this->runCommand(
            request: $request,
            definition: new CommandDefinition(
                name: 'org.department.member.remove',
                capability: 'org.unit.manage',
                anchor: \App\Shared\Authorization\AnchorType::ORG_UNIT,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'people.department.member_removed',
            ),
            payload: [
                'org_unit_id' => $id,
                'employment_id' => $employmentId,
                'effective_from' => (string) $data['effective_from'],
            ],
            operation: fn (): array => [
                'http_status' => 200,
                'body' => [
                    ...$this->membership->removeMember(
                        $id,
                        $employmentId,
                        (string) $data['effective_from'],
                        $principal->userId(),
                    ),
                    'reason' => (string) $data['reason'],
                ],
                'resource_type' => 'assignment',
            ],
        );

        return $this->respond($result);
    }

    private function assertUnit(int $id): OrgUnit
    {
        $unit = OrgUnit::query()->whereKey($id)->first();

        if ($unit === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $unit;
    }

    private function assertCapability(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today()->value();

        if (! $this->authorizer->permits($principal, $capability, $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): array{http_status: int, body: array<string, mixed>, resource_type?: string, resource_id?: string}  $operation
     */
    private function runCommand(
        Request $request,
        CommandDefinition $definition,
        array $payload,
        Closure $operation,
    ): CommandResult {
        return $this->commands->run(
            definition: $definition,
            payload: $payload,
            operation: $operation,
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );
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

    /**
     * @param  list<int>  $orgUnitIds
     * @return array<int, int>
     */
    private function headcountLimits(array $orgUnitIds, string $on): array
    {
        if ($orgUnitIds === []) {
            return [];
        }

        return array_reduce(
            $this->staffingWriter->snapshot($on),
            static function (array $carry, $snapshot) use ($orgUnitIds): array {
                if (in_array($snapshot->orgUnitId, $orgUnitIds, true) && $snapshot->headcountLimit !== null) {
                    $carry[$snapshot->orgUnitId] = $snapshot->headcountLimit;
                }

                return $carry;
            },
            [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(OrgUnit $unit, ?int $headcount, ?int $headcountLimit): array
    {
        $unitLimit = $unit->getAttribute('headcount_limit');
        $limit = $headcountLimit ?? ($unitLimit !== null ? (int) $unitLimit : null);

        return [
            'id' => (int) $unit->id,
            'parent_id' => $unit->parent_id !== null ? (int) $unit->parent_id : null,
            'code' => (string) $unit->code,
            'name' => (string) $unit->name,
            'type' => (string) $unit->type,
            'status' => (string) $unit->status,
            'manager_employment_id' => $unit->getAttribute('manager_employment_id') !== null
                ? (int) $unit->getAttribute('manager_employment_id')
                : null,
            'headcount_limit' => $limit,
            'headcount' => $headcount,
            'direct_headcount' => $headcount,
            'valid_from' => (string) $unit->valid_from,
            'valid_to' => (string) $unit->valid_to,
        ];
    }
}
