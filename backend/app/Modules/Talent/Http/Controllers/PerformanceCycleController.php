<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\Talent\Models\PerformanceReview;
use App\Modules\Talent\Services\PerformanceCycleService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin HTTP adapter for the K8 performance cycle.
 *
 * Writes are delegated to CommandRunner (idempotency + company context) and
 * reads to ProjectionResponder (ADR-0029 envelope). Capability and anchor
 * enforcement remain route middleware concerns.
 */
final class PerformanceCycleController
{
    public function __construct(
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly PerformanceCycleService $performance,
        private readonly CompanyBusinessDate $businessDates,
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly PeopleDirectory $people,
    ) {}

    public function indicators(Request $request): JsonResponse
    {
        return $this->projections->ok(
            $this->performance->indicators($this->asOf($request)),
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

        return $this->runCommand($request, 'performance.indicator', $data, function (int $actorUserId) use ($data): array {
            return $this->performance->createIndicator($actorUserId, $data);
        }, 201, 'performance.indicator.manage');
    }

    public function goals(Request $request): JsonResponse
    {
        return $this->projections->ok(
            $this->performance->goals($this->asOf($request)),
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

        return $this->runCommand($request, 'performance.goal', $data, function (int $actorUserId) use ($data): array {
            return $this->performance->createGoal($actorUserId, $data);
        }, 201, 'performance.goal.manage');
    }

    public function feedback(Request $request): JsonResponse
    {
        return $this->projections->ok(
            $this->performance->feedback($this->asOf($request)),
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

        return $this->runCommand($request, 'performance.feedback', $data, function (int $actorUserId) use ($data): array {
            return $this->performance->createFeedback($actorUserId, $data);
        }, 201, 'performance.feedback.manage');
    }

    public function calibrations(Request $request): JsonResponse
    {
        return $this->projections->ok(
            $this->performance->calibrations($this->asOf($request)),
        );
    }

    public function storeCalibration(Request $request, int $reviewId): JsonResponse
    {
        $data = $request->validate([
            'calibrated_rating' => ['required', 'string', 'max:16'],
            'reason' => ['required', 'string', 'min:3', 'max:4000'],
        ]);

        return $this->runCommand($request, 'performance.calibration', $data, function (int $actorUserId) use ($reviewId, $data): array {
            return $this->performance->calibrate($actorUserId, $reviewId, $data);
        }, 200, 'performance.calibration.manage');
    }

    public function results(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $asOf = $this->asOf($request);

        // Publishing is company-wide. A SELF-only reader may see the published
        // result of their own employment, never the company roster. Managed
        // readers are narrowed to their review scope; an empty scope is never
        // widened to the full ledger.
        if (! $this->authorizer->allows($principal, 'performance.result.publish', AnchorRef::company(), $asOf)) {
            $employmentId = $principal->employmentId() ?? $this->people->employmentForUser($principal->userId());

            if (! $this->authorizer->allows($principal, 'performance.cycle.read', AnchorRef::self(), $asOf)
                || $employmentId === null) {
                $cycleIds = $this->authorizer
                    ->constrain(PerformanceReview::query(), $principal, 'performance.cycle.read', $asOf)
                    ->distinct()
                    ->pluck('cycle_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();

                return $this->resultResponse($this->performance->results($asOf, null, $cycleIds));
            }

            return $this->resultResponse($this->performance->results($asOf, (int) $employmentId));
        }

        return $this->resultResponse($this->performance->results($asOf));
    }

    private function resultResponse(ProjectionResult $result): JsonResponse
    {
        $drilldown = is_array($result->drilldown) ? $result->drilldown : [];

        return $this->projections->ok($result, [
            'snapshot' => ($drilldown['snapshot'] ?? false) === true,
            'pending' => ($drilldown['pending'] ?? false) === true,
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        return $this->projections->ok(
            $this->performance->reports($this->asOf($request)),
        );
    }

    public function publishCycle(Request $request, int $cycleId): JsonResponse
    {
        return $this->runCommand($request, 'performance.publish', ['cycle_id' => $cycleId], function (int $actorUserId) use ($cycleId): array {
            return $this->performance->publish($actorUserId, $cycleId);
        }, 200, 'performance.result.publish');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  \Closure(int): array<string, mixed>  $operation
     */
    private function runCommand(
        Request $request,
        string $scope,
        array $payload,
        \Closure $operation,
        int $successStatus = 201,
        string $capability = 'performance.cycle.manage',
    ): JsonResponse {
        $definition = new CommandDefinition(
            name: 'talent.performance.'.$scope,
            capability: $capability,
            anchor: AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: 'talent.performance.'.$scope,
        );

        $principal = app(PrincipalResolver::class)->resolve();

        $result = $this->commands->run(
            $definition,
            $payload,
            function () use ($operation, $principal, $successStatus): array {
                return [
                    'http_status' => $successStatus,
                    'body' => $operation($principal->userId()),
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

    private function asOf(Request $request): string
    {
        return $this->businessDates->resolve(
            is_string($request->query('as_of')) ? $request->query('as_of') : null,
        )->value();
    }
}
