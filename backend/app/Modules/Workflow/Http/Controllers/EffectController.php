<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Http\Controllers\Concerns\RespondsToWorkflowCommands;
use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
use App\Modules\Workflow\Models\Effect;
use App\Modules\Workflow\Services\CaseQueryService;
use App\Modules\Workflow\Services\WorkflowCommandService;
use App\Shared\Exceptions\NotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EffectController
{
    use RespondsToWorkflowCommands;

    public function __construct(
        private readonly WorkflowCommandService $commands,
        private readonly CaseQueryService $queries,
    ) {}

    public function retry(Request $request, int $id): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $effect = Effect::query()
            ->whereKey($id)
            ->first();

        if ($effect === null) {
            throw NotFoundException::forResource();
        }

        // Reading first keeps 404 semantics for an unreadable case. The
        // executor repeats the administrative capability check so a direct
        // service caller cannot bypass it.
        $this->queries->detail($principal, $effect->case_id);

        $result = $this->commands->retryEffect(
            $principal,
            $effect,
            (string) $validated['reason'],
            $this->workflowIdempotencyKey($request),
        );

        return $this->commandResponse($result);
    }
}
