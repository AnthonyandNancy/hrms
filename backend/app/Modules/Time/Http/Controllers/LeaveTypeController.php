<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\LeavePolicyService;
use App\Modules\Time\Services\LeaveTypeService;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Leave-type identity and display catalog. Rule facts live exclusively in
 * leave_policy_versions; this controller never writes the legacy rule columns.
 */
final class LeaveTypeController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly LeaveTypeService $types,
        private readonly LeavePolicyService $policies,
        private readonly CommandRunner $commands,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->scope->actor($request);
        $status = strtoupper((string) $request->query('status', ''));
        $query = \App\Modules\Time\Models\LeaveType::query()->orderBy('code');

        if (in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            $query->where('status', $status);
        }

        $items = [];
        $latest = $this->policies->latestPublishedByType();

        foreach ($query->get() as $type) {
            $policy = $latest[(int) $type->id] ?? null;
            $items[] = [
                'id' => (int) $type->id,
                'code' => (string) $type->code,
                'name' => (string) $type->name,
                'unit' => (string) $type->unit,
                'is_statutory' => (bool) $type->is_statutory,
                'is_annual' => (bool) $type->is_annual,
                'requires_attachment' => (bool) $type->requires_attachment,
                'allow_negative' => (bool) $type->allow_negative,
                'status' => (string) ($type->status ?? 'ACTIVE'),
                'policy_version' => $policy?->version,
                'policy_version_id' => $policy?->id,
                'entitlement_minutes' => $policy !== null
                    ? (int) ($policy->accrual_json['minutes_per_year'] ?? 0)
                    : null,
                'version' => (int) $type->version,
            ];
        }

        return \App\Shared\ApiResponse::ok(
            \App\Shared\ApiResponse::list($items, 1, max(1, count($items)), count($items)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'min:2', 'max:32', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'name' => ['required', 'string', 'min:1', 'max:128'],
            'unit' => ['required', 'string', 'in:DAY,HOUR'],
            'is_statutory' => ['sometimes', 'boolean'],
            'is_annual' => ['sometimes', 'boolean'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'allow_negative' => ['sometimes', 'boolean'],
        ]);

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.leave.type.create',
                capability: 'time.leave.type.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.leave.type.created',
            ),
            $data,
            function () use ($data, $actor): array {
                $row = $this->types->create($data, $actor->userId);

                return [
                    'http_status' => 201,
                    'body' => \App\Shared\ApiResponse::created($row)->getData(true),
                    'resource_type' => 'leave_type',
                    'resource_id' => (string) $row['id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            // Immutable identity fields are accepted by the validator so the
            // service can return a stable 422 instead of silently ignoring
            // an attempted code/unit mutation.
            'code' => ['sometimes', 'string', 'max:32'],
            'unit' => ['sometimes', 'string', 'in:DAY,HOUR'],
            'name' => ['sometimes', 'string', 'min:1', 'max:128'],
            'is_statutory' => ['sometimes', 'boolean'],
            'is_annual' => ['sometimes', 'boolean'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'allow_negative' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,INACTIVE'],
        ]);

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.leave.type.update',
                capability: 'time.leave.type.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.leave.type.updated',
            ),
            ['id' => $id, ...$data],
            fn (): array => [
                'http_status' => 200,
                'body' => \App\Shared\ApiResponse::ok($this->types->update($id, $data, $actor->userId))->getData(true),
                'resource_type' => 'leave_type',
                'resource_id' => (string) $id,
            ],
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    private function respond(CommandResult $result): JsonResponse
    {
        $response = new JsonResponse($result->body, $result->status);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
