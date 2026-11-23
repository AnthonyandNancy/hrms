<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Modules\Talent\Models\TrainingProgram;
use App\Modules\Talent\Services\TrainingService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\TenantBusinessDate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TrainingController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TenantBusinessDate $businessDates,
        private readonly TrainingService $training,
        private readonly IdempotencyRunner $idempotency,
    ) {}

    public function storeProgram(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'training.program.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'],
            'default_capacity' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        return $this->command($request, 'program', $data, fn (): array => $this->training->createProgram($principal->tenantId(), $principal->userId(), $data));
    }

    public function programs(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'training.program.read');
        $items = TrainingProgram::query()
            ->where('tenant_id', $principal->tenantId())
            ->orderBy('id')
            ->get()
            ->map(static fn (TrainingProgram $program): array => [
                'program_id' => (int) $program->id,
                'code' => (string) $program->code,
                'title' => (string) $program->title,
                'status' => (string) $program->status,
            ])->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function storeSession(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'training.session.manage');
        $data = $request->validate([
            'program_id' => ['required', 'integer', 'min:1'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'location' => ['nullable', 'string', 'max:160'],
        ]);

        return $this->command($request, 'session', $data, fn (): array => $this->training->createSession($principal->tenantId(), $principal->userId(), $data));
    }

    public function storeEnrollment(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'training.enrollment.manage');
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'min:1'],
            'employment_id' => ['required', 'integer', 'min:1'],
        ]);

        return $this->command($request, 'enrollment', $data, fn (): array => $this->training->enroll($principal->tenantId(), $principal->userId(), (int) $data['session_id'], (int) $data['employment_id']));
    }

    public function completeEnrollment(Request $request, int $enrollmentId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'training.completion.manage');
        $data = $request->validate([
            'attended' => ['sometimes', 'boolean'],
            'completion_result' => ['required', 'string', 'in:PASS,FAIL,ATTENDED'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        return $this->command($request, "enrollment-{$enrollmentId}-complete", $data, fn (): array => $this->training->complete($principal->tenantId(), $principal->userId(), $enrollmentId, $data), 200);
    }

    public function report(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'training.report.read');
        $items = $this->training->report(
            $principal->tenantId(),
            $request->query('program_id') !== null ? (int) $request->query('program_id') : null,
            $request->query('employment_id') !== null ? (int) $request->query('employment_id') : null,
        );

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  \Closure(): array<string, mixed>  $operation
     */
    private function command(Request $request, string $scope, array $data, \Closure $operation, int $successStatus = 201): JsonResponse
    {
        $principal = $this->principals->resolve();
        $key = (string) $request->header('Idempotency-Key', '');

        if ($key === '') {
            throw new ValidationException(['idempotency_key' => ['A valid Idempotency-Key header is required.']]);
        }

        $result = DB::transaction(fn (): \App\Modules\Platform\Contracts\IdempotentResult => $this->idempotency->run(
            tenantId: $principal->tenantId(),
            principalId: $principal->userId(),
            scope: 'POST:/v1/training/'.$scope,
            key: $key,
            payload: $data,
            operation: function () use ($operation): array {
                $body = $operation();

                return ['http_status' => 201, 'body' => $body, 'resource_type' => 'talent'];
            },
        ));

        $response = $result->replayed || $successStatus === 200 ? ApiResponse::ok($result->body) : ApiResponse::created($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    private function allow(Principal $principal, string $capability): void
    {
        $today = $this->businessDates->today($principal->tenantId())->value();

        if (! $this->authorizer->allows($principal, $capability, AnchorRef::legalEntity(1), $today)) {
            throw new AuthorizationException('Forbidden.');
        }
    }
}
