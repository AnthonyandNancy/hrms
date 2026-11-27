<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Http\Controllers\Concerns\RespondsToWorkflowCommands;
use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
use App\Modules\Workflow\Services\CaseQueryService;
use App\Modules\Workflow\Services\WorkflowCommandService;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CaseController
{
    use RespondsToWorkflowCommands;

    /** @var list<string> */
    public const CASE_VIEWS = ['my_approvals', 'my_requests', 'my_decided', 'my_cc'];

    public function __construct(
        private readonly WorkflowCommandService $commands,
        private readonly CaseQueryService $queries,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);
        $filters = $request->query();
        $view = $filters['view'] ?? null;

        // Unknown view values must fail loudly. Silently falling back to the
        // visible set turns a client typo into a data-scope surprise.
        if ($view !== null && (! is_string($view) || ! in_array($view, self::CASE_VIEWS, true))) {
            throw new ValidationException(
                ['view' => ['Unknown case view.']],
                'The given data was invalid.',
                ['reason' => 'INVALID_CASE_VIEW'],
            );
        }

        $data = $this->queries->list($principal, $filters);

        return ApiResponse::ok(ApiResponse::list($data['items'], $data['page'], $data['page_size'], $data['total']));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);

        return ApiResponse::ok($this->queries->detail($principal, $id));
    }

    public function timeline(Request $request, int $id): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);

        return ApiResponse::ok($this->queries->timeline($principal, $id));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'subject_type' => ['required', 'string', 'max:64'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'payload' => ['required', 'array'],
            'template_id' => ['required', 'integer', 'min:1'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        return $this->commandResponse(
            $this->commands->createCase($principal, $validated, $this->workflowIdempotencyKey($request)),
        );
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);
        $case = $this->queries->resolveWritableCase($principal, $id);

        return $this->commandResponse(
            $this->commands->submitCase($principal, $case, $this->workflowIdempotencyKey($request)),
        );
    }

    public function withdraw(Request $request, int $id): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $case = $this->queries->resolveWritableCase($principal, $id);

        return $this->commandResponse(
            $this->commands->withdrawCase(
                $principal,
                $case,
                (string) $validated['comment'],
                $this->workflowIdempotencyKey($request),
            ),
        );
    }
}
