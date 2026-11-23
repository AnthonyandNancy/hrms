<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\EffectHandler;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Domain\EffectRegistry;
use App\Modules\Workflow\Domain\EffectResult;
use App\Modules\Workflow\Enums\EffectStatus;
use App\Modules\Workflow\Models\CaseEvent;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Effect;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies one effect at a time. The caller decides whether this runs inside the
 * approval transaction; retries and due-scheduler runs call it with their own
 * transaction and row lock.
 */
final class EffectExecutor
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly EffectRegistry $registry,
        private readonly Clock $clock,
        private readonly OutboxRecorder $outbox,
        private readonly AuditRecorder $audit,
        private readonly EffectProjectionService $projection,
    ) {}

    /**
     * Apply an already-persisted SCHEDULED effect.
     *
     * The effect row is locked FOR UPDATE so duplicate consumers serialize.
     * APPLIED and REVERSED are terminal and return without invoking a handler;
     * FAILED may be retried until MAX_ATTEMPTS. Handlers run in a savepoint, so
     * an exception discards all handler writes before FAILED is recorded.
     */
    public function apply(int $tenantId, int $effectId, bool $allowFailedRetry = false): EffectResult
    {
        return DB::transaction(function () use ($tenantId, $effectId, $allowFailedRetry): EffectResult {
            /** @var Effect $effect */
            $effect = Effect::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($effectId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($effect->status === EffectStatus::APPLIED) {
                return EffectResult::applied();
            }

            if ($effect->status === EffectStatus::REVERSED) {
                return EffectResult::reversed();
            }

            if ($effect->status === EffectStatus::FAILED) {
                if (! $allowFailedRetry) {
                    return new EffectResult(EffectStatus::FAILED);
                }

                if ($effect->attempts >= self::MAX_ATTEMPTS) {
                    $effect->forceFill(['requires_escalation' => true])->save();

                    return new EffectResult(EffectStatus::FAILED);
                }
            }

            if ($effect->status === EffectStatus::SCHEDULED && $effect->scheduled_for !== null) {
                $today = $this->clock->now()->toDateString();

                if ($effect->scheduled_for->toDateString() > $today) {
                    return EffectResult::scheduled();
                }
            }

            return $this->executeLocked($effect);
        });
    }

    /**
     * Execute while the caller holds the effect row lock. The handler runs in a
     * nested transaction = SQLite/MySQL SAVEPOINT. Any exception rolls back
     * handler writes, then FAILED/attempts are persisted in the outer scope.
     */
    public function executeLocked(Effect $effect): EffectResult
    {
        $handler = $this->registry->resolve($effect->effect_type);
        $now = $this->clock->now();

        // attempts increments only for an actual handler invocation.
        $effect->forceFill([
            'attempts' => $effect->attempts + 1,
            'failure_reason' => null,
        ])->save();

        try {
            $result = DB::transaction(fn (): EffectResult => $handler->apply($effect));
        } catch (Throwable $exception) {
            $this->recordFailure($effect, $exception, $now);

            return new EffectResult(EffectStatus::FAILED);
        }

        $this->recordSuccess($effect, $handler, $result, $now);

        return $result;
    }

    private function recordSuccess(Effect $effect, EffectHandler $handler, EffectResult $result, \Carbon\CarbonImmutable $now): void
    {
        $status = $result->status === EffectStatus::SCHEDULED || $result->status === EffectStatus::FAILED
            ? EffectStatus::SCHEDULED
            : EffectStatus::APPLIED;

        $effect->forceFill([
            'status' => $status,
            'applied_at' => $status === EffectStatus::APPLIED ? $now : null,
            'failed_at' => null,
            'failure_reason' => null,
            'requires_escalation' => false,
        ])->save();

        $this->publishOutboxFor($effect, $result);

        $effect->case?->refresh();
        $case = $effect->case;
        if ($case instanceof CaseModel) {
            $this->projection->refresh($case);
        }

        CaseEvent::query()->create([
            'tenant_id' => $effect->tenant_id,
            'case_id' => $effect->case_id,
            'event_type' => $status === EffectStatus::APPLIED ? 'effect.applied' : 'effect.scheduled',
            'actor_user_id' => null,
            'payload_json' => [
                'case_id' => $effect->case_id,
                'effect_id' => $effect->id,
                'effect_type' => $effect->effect_type,
                'status' => $status->value,
                'attempts' => $effect->attempts,
            ],
            'occurred_at' => $now,
        ]);
    }

    private function recordFailure(Effect $effect, Throwable $exception, \Carbon\CarbonImmutable $now): void
    {
        $effect->forceFill([
            'status' => EffectStatus::FAILED,
            'failed_at' => $now,
            'failure_reason' => mb_substr($exception->getMessage(), 0, 1000),
            'requires_escalation' => $effect->attempts >= self::MAX_ATTEMPTS,
        ])->save();

        $this->projection->refresh($effect->case);

        CaseEvent::query()->create([
            'tenant_id' => $effect->tenant_id,
            'case_id' => $effect->case_id,
            'event_type' => 'effect.failed',
            'actor_user_id' => null,
            'payload_json' => [
                'case_id' => $effect->case_id,
                'effect_id' => $effect->id,
                'effect_type' => $effect->effect_type,
                'status' => EffectStatus::FAILED->value,
                'attempts' => $effect->attempts,
                'requires_escalation' => $effect->requires_escalation,
            ],
            'occurred_at' => $now,
        ]);
    }

    private function publishOutboxFor(Effect $effect, EffectResult $result): void
    {
        foreach ($result->outboxEvents as $event) {
            $this->outbox->record(
                tenantId: $effect->tenant_id,
                eventType: (string) $event['event_type'],
                aggregateType: $effect->aggregate_type,
                aggregateId: $effect->aggregate_id,
                aggregateVersion: $effect->effect_seq,
                payload: $this->sanitizeOutboxPayload($effect, (array) $event['payload']),
            );
        }
    }

    /**
     * Force the outbox envelope to ID/version-only references. Effect handlers
     * cannot accidentally leak case payload fields into an integration event.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, int|string|null>
     */
    private function sanitizeOutboxPayload(Effect $effect, array $payload): array
    {
        $safe = [];

        foreach (['case_id', 'decision_id', 'effect_id', 'template_version', 'employment_id', 'aggregate_version', 'schema_version'] as $key) {
            if (array_key_exists($key, $payload)) {
                $value = $payload[$key];
                if (is_int($value) || is_string($value) || $value === null) {
                    $safe[$key] = $value;
                }
            }
        }

        $safe['case_id'] ??= $effect->case_id;
        $safe['effect_id'] ??= $effect->id;
        $safe['decision_id'] ??= $effect->decision_id;
        $safe['aggregate_version'] ??= $effect->effect_seq;

        return $safe;
    }

    /**
     * Retry a FAILED effect. Terminal effects are a no-op. Returns null when
     * the retry budget is exhausted and escalation was flagged.
     */
    public function retry(int $tenantId, int $effectId, WorkflowPrincipal $principal, ?string $reason = null): EffectResult
    {
        return DB::transaction(function () use ($tenantId, $effectId, $principal, $reason): EffectResult {
            /** @var Effect $effect */
            $effect = Effect::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($effectId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeEffect($principal, $effect);

            if ($effect->status === EffectStatus::APPLIED || $effect->status === EffectStatus::REVERSED) {
                return $effect->status === EffectStatus::APPLIED ? EffectResult::applied() : EffectResult::reversed();
            }

            if ($effect->status !== EffectStatus::FAILED) {
                throw new ConflictException('Only a failed effect can be retried.', [
                    'reason' => 'EFFECT_NOT_RETRYABLE',
                    'effect_id' => $effect->id,
                    'status' => $effect->status->value,
                ]);
            }

            if ($effect->attempts >= self::MAX_ATTEMPTS) {
                $effect->forceFill(['requires_escalation' => true])->save();

                throw new ConflictException('The effect has exhausted its retry budget.', [
                    'reason' => 'EFFECT_RETRY_EXHAUSTED',
                    'effect_id' => $effect->id,
                    'attempts' => $effect->attempts,
                ]);
            }

            $this->audit($principal, $effect, 'workflow.effect.retry_requested', $reason);

            return $this->executeLocked($effect);
        });
    }

    private function authorizeEffect(WorkflowPrincipal $principal, Effect $effect): void
    {
        if ($effect->tenant_id !== $principal->tenantId) {
            throw NotFoundException::forResource();
        }

        if (! $principal->can('workflow.case.admin')) {
            throw NotFoundException::forResource();
        }
    }

    private function audit(WorkflowPrincipal $principal, Effect $effect, string $action, ?string $reason = null): void
    {
        $this->audit->record(
            tenantId: $principal->tenantId,
            actorUserId: $principal->userId,
            action: $action,
            aggregateType: 'effect',
            aggregateId: (string) $effect->id,
            metadata: [
                'case_id' => $effect->case_id,
                'effect_id' => $effect->id,
                'attempts' => $effect->attempts,
                'reason' => $reason,
            ],
        );
    }
}
