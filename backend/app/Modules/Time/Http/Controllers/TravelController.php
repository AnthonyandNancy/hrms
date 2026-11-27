<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\TravelService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NoActiveEmploymentException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Projections\ProjectionResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Self-service travel requests plus the manager approval command.
 *
 * Approval is a Workflow Case decision delegated through TravelService. The
 * controller never writes a status; the expense endpoint is a separate claim
 * command that cannot create a payment.
 */
final class TravelController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly TravelService $travel,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly PersonEmploymentDirectory $people,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $today = $this->scope->today()->value();
        $requestedView = $request->query('view');
        $view = is_string($requestedView) && $requestedView !== '' ? $requestedView : 'my';
        $employmentId = $view === 'my'
            ? $this->selfEmploymentId($actor)
            : ($actor->employmentId ?? 0);
        $data = $request->validate([
            'view' => ['sometimes', 'nullable', 'string', 'in:my,my_approvals,team'],
            'status' => ['sometimes', 'nullable', 'string', 'in:DRAFT,PENDING_APPROVAL,APPROVED,REJECTED,CANCELLED,COMPLETED'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (! isset($data['view']) || $data['view'] === '') {
            $data['view'] = $view;
        }

        $employmentScope = $this->employmentScope($actor);
        $departmentEmploymentIds = null;

        if (isset($data['employment_id'])) {
            $this->scope->employment($actor, (int) $data['employment_id'], $today);
        }

        if (isset($data['department_id'])) {
            $departmentEmploymentIds = $this->people->employmentIdsForOrgUnits(
                [(int) $data['department_id']],
                $today,
            );

            if ($departmentEmploymentIds === []) {
                throw new NotFoundOrForbiddenException;
            }

            if ($employmentScope !== null) {
                $departmentEmploymentIds = array_values(array_intersect($employmentScope, $departmentEmploymentIds));

                if ($departmentEmploymentIds === []) {
                    throw new NotFoundOrForbiddenException;
                }
            }
        }

        $result = $this->travel->requestProjection(
            selfEmploymentId: $employmentId,
            managedEmploymentIds: $actor->managedEmploymentIds,
            employmentScope: $employmentScope,
            filters: [
                'view' => isset($data['view']) ? (string) $data['view'] : $view,
                'status' => isset($data['status']) && $data['status'] !== '' ? (string) $data['status'] : null,
                'from' => isset($data['from']) && $data['from'] !== '' ? (string) $data['from'] : null,
                'to' => isset($data['to']) && $data['to'] !== '' ? (string) $data['to'] : null,
                'employment_id' => isset($data['employment_id']) && (int) $data['employment_id'] > 0 ? (int) $data['employment_id'] : null,
                'department_id' => isset($data['department_id']) && (int) $data['department_id'] > 0 ? (int) $data['department_id'] : null,
                'employment_ids' => $departmentEmploymentIds,
            ],
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['page_size'] ?? 20),
            asOf: $today,
        );

        return $this->projections->ok($result);
    }

    public function change(Request $request, int $id): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'destination' => ['required', 'string', 'max:160'],
            'purpose' => ['required', 'string', 'min:3', 'max:500'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $employmentId = $this->selfEmploymentId($actor);
        $payload = [
            'employment_id' => $employmentId,
            'travel_id' => $id,
            'from_date' => (string) $data['from_date'],
            'to_date' => (string) $data['to_date'],
            'destination' => (string) $data['destination'],
            'purpose' => (string) $data['purpose'],
            'reason' => (string) $data['reason'],
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.travel.request.change',
                capability: 'time.travel.request',
                anchor: AnchorType::SELF,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.travel.change_requested',
            ),
            $payload,
            function () use ($actor, $employmentId, $id, $payload): array {
                $body = $this->travel->requestChange($employmentId, $id, $payload, $actor->userId);

                return [
                    'http_status' => 201,
                    'body' => $body,
                    'resource_type' => 'travel_request_change',
                    'resource_id' => (string) $body['change_id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'destination' => ['required', 'string', 'max:160'],
            'purpose' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $employmentId = $this->selfEmploymentId($actor);
        $fromDate = (string) $data['from_date'];
        $payload = [
            'employment_id' => $employmentId,
            'from_date' => $fromDate,
            'to_date' => (string) $data['to_date'],
            'destination' => (string) $data['destination'],
            'purpose' => (string) $data['purpose'],
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.travel.request.open',
                capability: 'time.travel.request',
                anchor: AnchorType::SELF,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.travel.requested',
            ),
            $payload,
            function () use ($actor, $payload): array {
                $body = $this->travel->openRequest((int) $payload['employment_id'], $payload, $actor->userId);

                return [
                    'http_status' => 201,
                    'body' => $body,
                    'resource_type' => 'business_trip',
                    'resource_id' => (string) $body['travel_id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $employmentId = $this->selfEmploymentId($actor);
        $payload = ['travel_id' => $id, 'reason' => (string) $data['reason']];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.travel.request.cancel',
                capability: 'time.travel.request',
                anchor: AnchorType::SELF,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.travel.cancelled',
            ),
            ['employment_id' => $employmentId, ...$payload],
            function () use ($actor, $employmentId, $id, $payload): array {
                $body = $this->travel->cancel($employmentId, $id, (string) $payload['reason'], $actor->userId);

                return [
                    'http_status' => 200,
                    'body' => $body,
                    'resource_type' => 'business_trip',
                    'resource_id' => (string) $id,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'decision' => ['required', 'string', Rule::in(['APPROVE', 'REJECT'])],
            'comment' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        $payload = [
            'travel_id' => $id,
            'decision' => (string) $data['decision'],
            'comment' => (string) ($data['comment'] ?? ''),
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.travel.request.decide',
                capability: 'time.travel.approve',
                anchor: AnchorType::MANAGED,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.travel.decided',
            ),
            $payload,
            function () use ($actor, $id, $payload): array {
                $companyWide = $actor->scope === 'COMPANY';
                $body = $this->travel->approve(
                    $id,
                    (string) $payload['decision'],
                    (string) $payload['comment'],
                    $actor->userId,
                    $actor->managedEmploymentIds,
                    $companyWide,
                );

                return [
                    'http_status' => 200,
                    'body' => $body,
                    'resource_type' => 'business_trip',
                    'resource_id' => (string) $id,
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    private function respond(CommandResult $result): JsonResponse
    {
        $response = match ($result->status) {
            201 => ApiResponse::created($result->body),
            default => ApiResponse::ok($result->body),
        };

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * @return list<int>|null null means the whole company
     */
    private function employmentScope(TimeActor $actor): ?array
    {
        return match ($actor->scope) {
            'SELF' => $actor->employmentId !== null ? [$actor->employmentId] : [],
            'MANAGED' => $actor->managedEmploymentIds,
            'COMPANY', 'HR', 'ADMIN' => null,
            default => [],
        };
    }

    private function selfEmploymentId(TimeActor $actor): int
    {
        if ($actor->employmentId === null || $actor->employmentId <= 0) {
            throw new NoActiveEmploymentException('time.travel.read');
        }

        return $actor->employmentId;
    }
}
