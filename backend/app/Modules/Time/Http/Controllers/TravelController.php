<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\TravelService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NoActiveEmploymentException;
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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $employmentId = $this->selfEmploymentId($actor);
        $data = $request->validate([
            'view' => ['sometimes', 'nullable', 'string', 'in:my_approvals'],
        ]);
        $approvalQueue = ($data['view'] ?? null) === 'my_approvals';
        $items = $this->travel->listRequests($employmentId, $actor->managedEmploymentIds, $approvalQueue);

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
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

    private function selfEmploymentId(TimeActor $actor): int
    {
        if ($actor->employmentId === null || $actor->employmentId <= 0) {
            throw new NoActiveEmploymentException('time.travel.read');
        }

        return $actor->employmentId;
    }
}
