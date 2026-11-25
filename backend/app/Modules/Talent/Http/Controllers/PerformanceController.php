<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Modules\Talent\Services\PerformanceService;
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

final class PerformanceController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TenantBusinessDate $businessDates,
        private readonly PerformanceService $performance,
        private readonly IdempotencyRunner $idempotency,
    ) {}

    public function cycles(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.cycle.read');
        $items = $this->performance->cycles($principal->tenantId());

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function reviews(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.review.manage');
        $items = $this->performance->reviews(
            $principal->tenantId(),
            $request->query('cycle_id') !== null ? (int) $request->query('cycle_id') : null,
            is_string($request->query('status')) ? $request->query('status') : null,
        );

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.cycle.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after:period_from'],
        ]);

        return $this->command($request, 'cycle', $data, fn (): array => $this->performance->createCycle($principal->tenantId(), $principal->userId(), $data));
    }

    public function storeReview(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.review.manage');
        $data = $request->validate([
            'cycle_id' => ['required', 'integer', 'min:1'],
            'employment_id' => ['required', 'integer', 'min:1'],
            'manager_employment_id' => ['nullable', 'integer', 'min:1'],
            'goals_text' => ['nullable', 'string', 'max:10000'],
            'self_assessment' => ['nullable', 'string', 'max:10000'],
            'self_rating' => ['nullable', 'string', 'max:16'],
        ]);

        return $this->command($request, 'review', $data, fn (): array => $this->performance->createReview($principal->tenantId(), $principal->userId(), $data));
    }

    public function submitReview(Request $request, int $reviewId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $this->allow($principal, 'performance.review.submit');
        $data = $request->validate([
            'manager_assessment' => ['nullable', 'string', 'max:10000'],
            'manager_rating' => ['nullable', 'string', 'max:16'],
            'calibrated_rating' => ['nullable', 'string', 'max:16'],
        ]);

        return $this->command($request, "review-{$reviewId}-submit", $data, fn (): array => $this->performance->submitReview($principal->tenantId(), $principal->userId(), $reviewId, $data), 200);
    }

    public function storeAppeal(Request $request, int $reviewId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $today = $this->businessDates->today($principal->tenantId());
        $this->allow($principal, 'performance.appeal.manage');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        return $this->command($request, "review-{$reviewId}-appeal", $data, fn (): array => $this->performance->createAppeal($principal->tenantId(), $principal->userId(), $reviewId, $data, $today->value()));
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
            scope: 'POST:/v1/performance/'.$scope,
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
