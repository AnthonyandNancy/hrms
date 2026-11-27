<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
use App\Modules\Workflow\Models\Effect;
use App\Modules\Workflow\Services\CaseQueryService;
use App\Modules\Workflow\Services\EffectExecutor;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\NotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EffectController
{
    public function __construct(
        private readonly EffectExecutor $executor,
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

        // Authorization is also enforced inside the executor; reading first
        // here keeps 404 semantics for unreadable cases.
        $this->queries->detail($principal, $effect->case_id);

        $result = $this->executor->retry($effect->id, $principal, (string) $validated['reason']);
        $fresh = $effect->fresh() ?? $effect;

        $data = [
            'effect_id' => $fresh->id,
            'case_id' => $fresh->case_id,
            'effect_status' => $fresh->status->value,
            'attempts' => $fresh->attempts,
            'requires_escalation' => $fresh->requires_escalation,
            'reason' => $validated['reason'],
        ];

        return $result->status === \App\Modules\Workflow\Enums\EffectStatus::APPLIED
            ? ApiResponse::ok($data)
            : ApiResponse::accepted($data);
    }
}
