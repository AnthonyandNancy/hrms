<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\Talent\Services\PerformanceCycleService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Time\TenantBusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin HTTP adapter for the K8 performance cycle.
 *
 * Writes are delegated to CommandRunner (idempotency + tenant resolution) and
 * reads to ProjectionResponder (ADR-0029 envelope). Capability and anchor
 * enforcement remain route middleware concerns.
 */
final class PerformanceCycleController
{
    public function __construct(
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly PerformanceCycleService $performance,
        private readonly TenantBusinessDate $businessDates,
    ) {}

    public function indicators(Request $request): JsonResponse
    {
        return $this->projections->ok(
            $this->performance->indicators($this->tenantId($request), $this->asOf($request)),
        );
    }

    public function storeIndicator(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:64'],
            'data_source' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:4000'],
            'active' => ['nullable', 'boolean'],
        ]);

        return $this->runCommand($request, 'performance.indicator', $data, function (int $tenantId, int $actorUserId) use ($data): array {
            return $this->performance->createIndicator($tenantId, $actorUserId, $data);
        });
    }

    public function goals(Request $request): JsonResponse
    {
        return $this->projections->ok(
            $this->performance->goals($this->tenantId($request), $this->asOf($request)),
        );
    }

    public function storeGoal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cycle_id' => ['required', 'integer', 'min:1'],
            'employment_id' => ['required', 'integer', 'min:1'],
            'indicator_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:200'],
            'target_value' => ['required', 'string', 'max:120'],
            'weight' => ['nullable', 'integer', 'min:0', 'max:100'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string', 'in:DRAFT,CONFIRMED,IN_PROGRESS,COMPLETED'],
        ]);

        return $this->runCommand($request, 'performance.goal', $data, function (int $tenantId, int $actorUserId) use ($data): array {
            return $this->performance->createGoal($tenantId, $actorUserId, $data);
        });
    }

    public function feedback(Request $request): JsonResponse
    {
        return $this->projections->ok(
            $this->performance->feedback($this->tenantId($request), $this->asOf($request)),
        );
    }

    public function storeFeedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cycle_id' => ['required', 'integer', 'min:1'],
            'review_id' => ['nullable', 'integer', 'min:1'],
            'from_employment_id' => ['required', 'integer', 'min:1'],
            'to_employment_id' => ['required', 'integer', 'min:1'],
            'feedback_type' => ['required', 'string', 'in:PEER,MANAGER,SELF,UPWARD,CROSS'],
            'rating' => ['nullable', 'string', 'max:16'],
            'comments' => ['required', 'string', 'max:10000'],
            'anonymous' => ['nullable', 'boolean'],
        ]);

        return $this->runCommand($request, 'performance.feedback', $data, function (int $tenantId, int $actorUserId) use ($data): array {
            return $this->performance->createFeedback($tenantId, $actorUserId, $data);
        });
    }

    public function calibrations(Request $request): JsonResponse
    {
        return $this->projections->ok(
            $this->performance->calibrations($this->tenantId($request), $this->asOf($request)),
        );
    }

    public function storeCalibration(Request $request, int $reviewId): JsonResponse
    {
        $data = $request->validate([
            'calibrated_rating' => ['required', 'string', 'max:16'],
            'reason' => ['required', 'string', 'min:3', 'max:4000'],
        ]);

        return $this->runCommand($request, 'performance.calibration', $data, function (int $tenantId, int $actorUserId) use ($reviewId, $data): array {
            return $this->performance->calibrate($tenantId, $actorUserId, $reviewId, $data);
        }, 200);
    }

    public function results(Request $request): JsonResponse
    {
        return $this->projections->ok(
            $this->performance->results($this->tenantId($request), $this->asOf($request)),
        );
    }

    public function reports(Request $request): JsonResponse
    {
        return $this->projections->ok(
            $this->performance->reports($this->tenantId($request), $this->asOf($request)),
        );
    }

    public function publishCycle(Request $request, int $cycleId): JsonResponse
    {
        return $this->runCommand($request, 'performance.publish', ['cycle_id' => $cycleId], function (int $tenantId, int $actorUserId) use ($cycleId): array {
            return $this->performance->publish($tenantId, $actorUserId, $cycleId);
        }, 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  \Closure(int, int): array<string, mixed>  $operation
     */
    private function runCommand(Request $request, string $scope, array $payload, \Closure $operation, int $successStatus = 201): JsonResponse
    {
        $definition = new CommandDefinition(
            name: 'talent.'.$scope,
            capability: 'performance.cycle.manage',
            anchor: AnchorType::LEGAL_ENTITY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'talent.'.$scope,
        );

        $principal = app(\App\Shared\Authorization\PrincipalResolver::class)->resolve();

        $result = $this->commands->run(
            $definition,
            $payload,
            function () use ($operation, $principal, $successStatus): array {
                return [
                    'http_status' => $successStatus,
                    'body' => $operation($principal->tenantId(), $principal->userId()),
                    'resource_type' => 'talent.performance',
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        $response = $result->status === 201 && ! $result->replayed
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    private function tenantId(Request $request): int
    {
        return app(\App\Shared\Authorization\PrincipalResolver::class)->resolve()->tenantId();
    }

    private function asOf(Request $request): string
    {
        $tenantId = $this->tenantId($request);

        return $this->businessDates->resolve(
            is_string($request->query('as_of')) ? $request->query('as_of') : null,
            $tenantId,
        )->value();
    }
}
