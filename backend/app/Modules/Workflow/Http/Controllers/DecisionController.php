<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Modules\Workflow\Enums\DecisionAction;
use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
use App\Modules\Workflow\Services\CaseQueryService;
use App\Modules\Workflow\Services\DecisionService;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DecisionController
{
    public function __construct(
        private readonly DecisionService $decisions,
        private readonly CaseQueryService $queries,
        private readonly IdempotencyRunner $idempotency,
    ) {}

    public function store(Request $request, int $caseId): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);

        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(array_map(static fn (DecisionAction $action): string => $action->value, DecisionAction::cases()))],
            'comment' => ['nullable', 'string', 'max:4000'],
            'target_user_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $key = $this->idempotencyKey($request);
        $action = DecisionAction::from((string) $validated['action']);

        // The idempotency reservation, immutable Decision, effect rows and
        // stored replay body all commit atomically. Local effect failures are
        // compensated inside DecisionService (see docs/06-workflows.md).
        $result = DB::transaction(fn (): \App\Modules\Platform\Contracts\IdempotentResult => $this->idempotency->run(
            tenantId: $principal->tenantId,
            principalId: $principal->userId,
            scope: "POST:/v1/cases/{$caseId}/decisions",
            key: $key,
            payload: $validated,
            operation: function () use ($principal, $caseId, $action, $validated): array {
                $case = $this->decisions->decide(
                    principal: $principal,
                    caseId: $caseId,
                    action: $action,
                    comment: (string) ($validated['comment'] ?? ''),
                    targetUserId: isset($validated['target_user_id']) ? (int) $validated['target_user_id'] : null,
                    reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
                );

                $data = $this->queries->detail($principal, $case->id);

                return [
                    'http_status' => 200,
                    'body' => [
                        'case_id' => $data['id'],
                        'application_status' => $data['application_status'],
                        'effect_status' => $data['effect_status'],
                        'effects' => $data['effects'],
                    ],
                    'resource_type' => 'case',
                    'resource_id' => (string) $case->id,
                ];
            },
        ));

        $response = ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '' || mb_strlen($key) > 128) {
            throw new ValidationException(
                ['idempotency_key' => ['A valid Idempotency-Key header is required.']],
                'A valid Idempotency-Key header is required.',
            );
        }

        return $key;
    }
}
