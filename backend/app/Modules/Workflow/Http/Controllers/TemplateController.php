<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
use App\Modules\Workflow\Services\TemplateService;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TemplateController
{
    public function __construct(private readonly TemplateService $templates) {}

    public function index(Request $request): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);

        $query = \App\Modules\Workflow\Models\WorkflowTemplate::query()
            ->when(is_string($request->query('code')), fn ($builder) => $builder->where('code', $request->query('code')))
            ->orderBy('code')
            ->orderBy('version');

        $templates = $query->get();

        $items = $templates->map(static fn (\App\Modules\Workflow\Models\WorkflowTemplate $template): array => [
            'id' => $template->id,
            'code' => $template->code,
            'name' => $template->name,
            'version' => $template->version,
            'status' => $template->status,
            'definition' => $template->definition_json,
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

        $template = $this->templates->create($principal->userId, $validated);

        return ApiResponse::created([
            'id' => $template->id,
            'code' => $template->code,
            'version' => $template->version,
            'status' => $template->status,
        ]);
    }
}
