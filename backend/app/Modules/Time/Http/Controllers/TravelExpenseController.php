<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Services\TravelService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Time\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Travel expense claims. The write is an idempotent command that requires an
 * APPROVED trip plus an APPROVED Workflow case; it never pays or posts to
 * payroll. Reads are projections scoped to the actor's legal entities.
 */
final class TravelExpenseController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly TravelService $travel,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'travel_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        [$employmentIds, $legalEntityIds] = $this->scope($actor);

        return $this->projections->ok($this->travel->expenseProjection(
            tenantId: $actor->tenantId,
            employmentIds: $employmentIds,
            legalEntityIds: $legalEntityIds,
            travelId: isset($data['travel_id']) ? (int) $data['travel_id'] : null,
            page: (int) ($data['page'] ?? 1),
            pageSize: (int) ($data['page_size'] ?? 20),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'travel_id' => ['required', 'integer', 'min:1'],
            'amount_cents' => ['required', 'integer', 'min:0'],
            'category' => ['required', 'string', Rule::in(['TRANSPORT', 'LODGING', 'MEALS', 'OTHER'])],
            'receipt_file_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $payload = [
            'travel_id' => (int) $data['travel_id'],
            'amount_cents' => (int) $data['amount_cents'],
            'category' => strtoupper((string) $data['category']),
            'receipt_file_id' => isset($data['receipt_file_id']) ? (int) $data['receipt_file_id'] : null,
            'note' => isset($data['note']) ? (string) $data['note'] : null,
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.travel.expense.claim',
                capability: 'time.travel.expense.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.travel.expense.claimed',
            ),
            $payload,
            function () use ($actor, $payload): array {
                [$employmentIds, $legalEntityIds] = $this->scope($actor);
                $body = $this->travel->recordExpense(
                    $actor->tenantId,
                    $payload,
                    $actor->userId,
                    $employmentIds,
                    $legalEntityIds,
                );

                return [
                    'http_status' => 201,
                    'body' => $body,
                    'resource_type' => 'travel_expense',
                    'resource_id' => (string) $body['id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        $response = $result->status === 201
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * @return array{0: list<int>, 1: list<int>}
     */
    private function scope(\App\Modules\Time\Contracts\TimeActor $actor): array
    {
        $today = $this->clock->now()->toDateString();

        return match ($actor->scope) {
            'SELF' => [$actor->employmentId !== null ? [$actor->employmentId] : [], []],
            'MANAGED' => [$actor->managedEmploymentIds, []],
            default => [[], $this->scope->legalEntityIds($actor, $today)],
        };
    }
}
