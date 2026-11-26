<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\OvertimeRequestService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OvertimeRequestController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly OvertimeRequestService $requests,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $asOf = $this->scope->resolveDate(
            $request->query('as_of') !== null ? (string) $request->query('as_of') : null,
        );

        $data = $request->validate([
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'string', 'in:SUBMITTED,APPROVED,CONFIRMED,REJECTED,CANCELLED'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $employmentId = isset($data['employment_id'])
            ? $this->scope->employment($actor, (int) $data['employment_id'], $asOf)->employmentId
            : $this->scope->selfEmployment($actor, $asOf)->employmentId;

        $result = $this->requests->project(
            employmentId: $employmentId,
            asOf: $asOf,
            status: isset($data['status']) ? (string) $data['status'] : null,
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['page_size'] ?? 20),
        );

        return $this->projections->ok($result);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'business_date' => ['required', new BusinessDateRule],
            'declared_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $businessDate = (string) $data['business_date'];
        $employmentId = $this->scope->selfEmployment($actor, $businessDate)->employmentId;

        $payload = [
            'employment_id' => $employmentId,
            'business_date' => $businessDate,
            'declared_minutes' => (int) $data['declared_minutes'],
            'reason' => (string) $data['reason'],
        ];

        $definition = new CommandDefinition(
            name: 'time.overtime.request.apply',
            capability: 'time.overtime.request',
            anchor: AnchorType::SELF,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'time.overtime.requested',
        );

        $result = $this->commands->run(
            definition: $definition,
            payload: $payload,
            operation: function () use ($actor, $payload): array {
                $created = $this->requests->apply(
                    employmentId: (int) $payload['employment_id'],
                    businessDate: (string) $payload['business_date'],
                    declaredMinutes: (int) $payload['declared_minutes'],
                    reason: (string) $payload['reason'],
                    actorUserId: $actor->userId,
                );

                return [
                    'http_status' => 201,
                    'body' => $this->requests->payload($created),
                    'resource_type' => 'overtime_request',
                    'resource_id' => (string) $created->id,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result, created: true);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'confirmed_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
        ]);

        $existing = $this->requests->find($id);

        if ($existing === null) {
            throw new NotFoundOrForbiddenException;
        }

        $this->scope->employment(
            $actor,
            (int) $existing->employment_id,
            $existing->business_date->format('Y-m-d'),
        );

        $payload = [
            'overtime_request_id' => $id,
            'confirmed_minutes' => (int) $data['confirmed_minutes'],
        ];

        $definition = new CommandDefinition(
            name: 'time.overtime.request.confirm',
            capability: 'time.overtime.confirm',
            anchor: AnchorType::MANAGED,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'time.overtime.confirmed',
        );

        $result = $this->commands->run(
            definition: $definition,
            payload: $payload,
            operation: function () use ($actor, $payload): array {
                $confirmed = $this->requests->confirm(
                    overtimeRequestId: (int) $payload['overtime_request_id'],
                    confirmedMinutes: (int) $payload['confirmed_minutes'],
                    actorUserId: $actor->userId,
                );

                return [
                    'http_status' => 200,
                    'body' => $this->requests->payload($confirmed),
                    'resource_type' => 'overtime_request',
                    'resource_id' => (string) $confirmed->id,
                ];
            },
            idempotencyKey: (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result, created: false);
    }

    public function timeoffBalances(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $asOf = $this->scope->resolveDate(
            $request->query('as_of') !== null ? (string) $request->query('as_of') : null,
        );

        $data = $request->validate([
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $employmentId = isset($data['employment_id'])
            ? $this->scope->employment($actor, (int) $data['employment_id'], $asOf)->employmentId
            : $this->scope->selfEmployment($actor, $asOf)->employmentId;

        $result = $this->requests->timeoffBalances($employmentId, $asOf);
        $available = array_reduce(
            $result->items,
            static fn (int $total, array $item): int => $total + (int) $item['minutes'],
            0,
        );

        return $this->projections->ok($result, [
            'employment_id' => $employmentId,
            'available_minutes' => $available,
            'settlement_count' => count($result->items),
        ]);
    }

    private function respond(CommandResult $result, bool $created): JsonResponse
    {
        $response = $created && ! $result->replayed
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
