<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
use App\Modules\Workflow\Services\CaseQueryService;
use App\Modules\Workflow\Services\CaseService;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CaseController
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseQueryService $queries,
        private readonly IdempotencyRunner $idempotency,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);
        $data = $this->queries->list($principal, $request->query());

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

        $key = $this->idempotencyKey($request);
        $payloadForHash = $validated;

        // Reservation, resource write, and stored replay response commit as
        // one unit. A crash cannot leave a completed case with an in-progress
        // idempotency key that would break a later safe replay.
        $result = DB::transaction(fn (): \App\Modules\Platform\Contracts\IdempotentResult => $this->idempotency->run(
            principalId: $principal->userId,
            scope: 'POST:/v1/cases',
            key: $key,
            payload: $payloadForHash,
            operation: function () use ($principal, $validated): array {
                $case = $this->cases->create(
                    principal: $principal,
                    type: $validated['type'],
                    subjectType: $validated['subject_type'],
                    subjectId: isset($validated['subject_id']) ? (int) $validated['subject_id'] : null,
                    title: $validated['title'],
                    payload: (array) $validated['payload'],
                    templateId: (int) $validated['template_id'],
                    businessDate: $validated['business_date'],
                    submit: (bool) ($validated['submit'] ?? false),
                );

                return [
                    'http_status' => 201,
                    'body' => [
                        'id' => $case->id,
                        'case_no' => $case->case_no,
                        'application_status' => $case->status->value,
                        'effect_status' => $case->effect_status->value,
                    ],
                    'resource_type' => 'case',
                    'resource_id' => (string) $case->id,
                ];
            },
        ));

        $response = $result->status === 201 ? ApiResponse::created($result->body) : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);
        $case = $this->queries->resolveWritableCase($principal, $id);
        $submitted = $this->cases->submit($principal, $case);

        return ApiResponse::ok([
            'id' => $submitted->id,
            'application_status' => $submitted->status->value,
            'effect_status' => $submitted->effect_status->value,
        ]);
    }

    public function withdraw(Request $request, int $id): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        $case = $this->queries->resolveWritableCase($principal, $id);
        $withdrawn = $this->cases->withdraw($principal, $case, (string) $validated['comment']);

        return ApiResponse::ok([
            'id' => $withdrawn->id,
            'application_status' => $withdrawn->status->value,
            'effect_status' => $withdrawn->effect_status->value,
        ]);
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
