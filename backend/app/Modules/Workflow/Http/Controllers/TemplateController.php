<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Http\Controllers\Concerns\RespondsToWorkflowCommands;
use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
use App\Modules\Workflow\Services\WorkflowCommandService;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TemplateController
{
    use RespondsToWorkflowCommands;

    public function __construct(private readonly WorkflowCommandService $commands) {}

    public function index(Request $request): JsonResponse
    {
        WorkflowPrincipalContext::from($request);

        $query = \App\Modules\Workflow\Models\WorkflowTemplate::query()
            ->when(is_string($request->query('code')), fn ($builder) => $builder->where('code', $request->query('code')))
            ->orderBy('code')
            ->orderByDesc('version');

        $templates = $query->get();

        $items = $templates->map(static fn (\App\Modules\Workflow\Models\WorkflowTemplate $template): array => [
            'id' => $template->id,
            'code' => $template->code,
            'name' => $template->name,
            'version' => $template->version,
            'status' => $template->status,
            'node_count' => count((array) ($template->definition_json['nodes'] ?? [])),
            'effect_count' => count((array) ($template->definition_json['effects'] ?? [])),
            'created_at' => $template->created_at?->toIso8601ZuluString(),
        ])->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, count($items), count($items)));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_.-]{1,63}$/'],
            'name' => ['required', 'string', 'max:128'],
            'version' => ['required', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', 'string', 'in:DRAFT,PUBLISHED,ARCHIVED'],
            'definition' => ['required', 'array'],
        ]);

        $result = $this->commands->createTemplate(
            $principal,
            $validated,
            $this->workflowIdempotencyKey($request),
        );

        return $this->commandResponse($result);
    }
}
