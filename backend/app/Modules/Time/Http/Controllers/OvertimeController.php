<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Models\OvertimeRecord;
use App\Modules\Time\Models\OvertimeSettlement;
use App\Modules\Time\Services\OvertimeSettlementService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class OvertimeController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly Authorizer $authorizer,
        private readonly TimeRequestScope $scope,
        private readonly OvertimeSettlementService $settlements,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
    ) {}

    /**
     * Settlement records for the caller scope. The `/overtime` settlement tab
     * uses this read model rather than inferring settlement state from requests.
     */
    public function settlements(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $asOf = $this->scope->resolveDate(
            $request->query('as_of') !== null ? (string) $request->query('as_of') : null,
        );
        $data = $request->validate([
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'method' => ['sometimes', 'nullable', 'string', 'in:PAY,TIMEOFF'],
        ]);

        $employmentId = isset($data['employment_id'])
            ? $this->scope->employment($actor, (int) $data['employment_id'], $asOf)->employmentId
            : $this->scope->selfEmployment($actor, $asOf)->employmentId;

        $records = OvertimeRecord::query()
            ->where('employment_id', $employmentId)
            ->where('business_date', '<=', $asOf)
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get();

        $settlements = OvertimeSettlement::query()
            ->whereIn('overtime_record_id', $records->modelKeys() === [] ? [-1] : $records->modelKeys())
            ->when(isset($data['method']), fn ($settlementQuery) => $settlementQuery->where('method', (string) $data['method']))
            ->get()
            ->keyBy('overtime_record_id');

        $items = $records
            ->map(function (OvertimeRecord $record) use ($settlements, $employmentId): array {
                $settlement = $settlements->get((int) $record->id);

                return [
                    'overtime_record_id' => (int) $record->id,
                    'employment_id' => (int) $record->employment_id,
                    'business_date' => $record->business_date->format('Y-m-d'),
                    'minutes' => (int) $record->minutes,
                    'status' => (string) $record->status,
                    'settlement_id' => $settlement?->id !== null ? (int) $settlement->id : null,
                    'method' => $settlement?->method,
                    'reason' => $settlement?->reason,
                    'recorded_at' => $settlement?->recorded_at?->toIso8601String(),
                    'payroll_period_frozen' => $this->payrollFrozen(
                        $employmentId,
                        $record->business_date->format('Y-m'),
                    ),
                ];
            })
            ->filter(static fn (array $row): bool => $row['settlement_id'] !== null)
            ->values()
            ->all();

        return $this->projections->ok(new ProjectionResult(
            items: $items,
            page: 1,
            pageSize: 100,
            total: count($items),
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: [
                'resource' => 'overtime_settlements',
                'filters' => [
                    'employment_id' => $employmentId,
                    'method' => isset($data['method']) ? (string) $data['method'] : null,
                ],
            ],
        ));
    }

    /**
     * Settle once through CommandRunner. Freeze truth comes only from the
     * read-only PayrollPeriodLockReader contract; no Payroll table is queried.
     */
    public function settle(Request $request, int $recordId): JsonResponse
    {
        $principal = $this->principals->resolve();
        $actor = $this->scope->actor($request);
        $today = $this->scope->today()->value();

        if (! $this->authorizer->permits($principal, 'time.overtime.settle', $today)) {
            throw new AuthorizationException('Forbidden.');
        }

        $record = OvertimeRecord::query()->whereKey($recordId)->first();

        if ($record === null) {
            throw new NotFoundOrForbiddenException;
        }

        // Missing and out-of-scope records render identically as 404.
        $this->scope->employment(
            $actor,
            (int) $record->employment_id,
            $record->business_date->format('Y-m-d'),
        );

        $data = $request->validate([
            'method' => ['required', 'string', 'in:PAY,TIMEOFF'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $payload = [
            'overtime_record_id' => $recordId,
            'method' => (string) $data['method'],
            'reason' => (string) $data['reason'],
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.overtime.settle',
                capability: 'time.overtime.settle',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.overtime.settled',
            ),
            $payload,
            function () use ($payload, $principal): array {
                $body = $this->settlements->settle(
                    (int) $payload['overtime_record_id'],
                    (string) $payload['method'],
                    (string) $payload['reason'],
                    $principal->userId(),
                );

                return [
                    'http_status' => ($body['replayed'] ?? false) === true ? 200 : 201,
                    'body' => $body,
                    'resource_type' => 'overtime_settlement',
                    'resource_id' => (string) $body['settlement_id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respond($result);
    }

    private function respond(CommandResult $result): JsonResponse
    {
        $businessReplayed = ($result->body['replayed'] ?? false) === true;
        $replayed = $result->replayed || $businessReplayed;
        $status = $replayed ? 200 : $result->status;
        $response = $status === 201
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body, $status);

        if ($replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    private function payrollFrozen(int $employmentId, string $period): ?bool
    {
        if (! app()->bound(PayrollPeriodLockReader::class)) {
            return null;
        }

        try {
            $reader = app(PayrollPeriodLockReader::class);

            if (! $reader instanceof PayrollPeriodLockReader) {
                return null;
            }

            return $reader->isFrozen($employmentId, $period);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
