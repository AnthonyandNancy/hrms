<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Enums\DecisionAction;
use App\Modules\Workflow\Http\Controllers\Concerns\RespondsToWorkflowCommands;
use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
use App\Modules\Workflow\Services\CaseQueryService;
use App\Modules\Workflow\Services\WorkflowCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DecisionController
{
    use RespondsToWorkflowCommands;

    public function __construct(
        private readonly WorkflowCommandService $commands,
        private readonly CaseQueryService $queries,
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

        $action = DecisionAction::from((string) $validated['action']);

        // The CommandRunner transaction holds the idempotency reservation,
        // immutable Decision, SAVEPOINT-guarded effects, audit row and stored
        // replay body. A local effect failure remains FAILED and is visible.
        $result = $this->commands->decide(
            principal: $principal,
            caseId: $caseId,
            action: $action,
            comment: (string) ($validated['comment'] ?? ''),
            targetUserId: isset($validated['target_user_id']) ? (int) $validated['target_user_id'] : null,
            reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
            idempotencyKey: $this->workflowIdempotencyKey($request),
            resultBody: function (\App\Modules\Workflow\Models\CaseModel $case) use ($principal): array {
                $data = $this->queries->detail($principal, $case->id);

                return [
                    'case_id' => $data['id'],
                    'application_status' => $data['application_status'],
                    'effect_status' => $data['effect_status'],
                    'effects' => $data['effects'],
                ];
            },
        );

        return $this->commandResponse($result);
    }
}
