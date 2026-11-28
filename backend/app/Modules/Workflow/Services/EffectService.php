<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Domain\EffectRegistry;
use App\Modules\Workflow\Enums\CaseEffectStatus;
use App\Modules\Workflow\Enums\EffectStatus;
use App\Modules\Workflow\Models\CaseEvent;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Decision;
use App\Modules\Workflow\Models\Effect;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;

final class EffectService
{
    public const EXECUTION_MODES = ['LOCAL', 'EXTERNAL', 'SCHEDULED'];

    public function __construct(
        private readonly EffectRegistry $registry,
        private readonly EffectExecutor $executor,
        private readonly OutboxRecorder $outbox,
        private readonly Clock $clock,
    ) {}

    /**
     * Validate all declared effects before any Decision is written. This makes
     * a typo a synchronous 422 instead of an undiagnosable failed effect later.
     *
     * @param  array<int, mixed>  $effects
     */
    public function validateDeclarations(array $effects): void
    {
        foreach ($effects as $index => $spec) {
            if (! is_array($spec)) {
                throw new ValidationException(['effects' => ["Effect {$index} must be an object."]], 'Invalid effect declaration.');
            }

            $type = $spec['type'] ?? null;
            if (! is_string($type) || $type === '') {
                throw new ValidationException(['effects' => ["Effect {$index} needs a type."]], 'Invalid effect declaration.');
            }

            $this->registry->resolve($type);

            $execution = $spec['execution'] ?? 'LOCAL';
            if (! is_string($execution) || ! in_array($execution, self::EXECUTION_MODES, true)) {
                throw new ValidationException(['effects' => ["Effect {$index} has an invalid execution mode."]], 'Invalid effect declaration.');
            }

            if (isset($spec['scheduled_for'])) {
                if (! is_string($spec['scheduled_for']) || ! $this->isDate($spec['scheduled_for'])) {
                    throw new ValidationException(['effects' => ["Effect {$index} has an invalid scheduled_for date."]], 'Invalid effect declaration.');
                }
            }

            if (isset($spec['scheduled_days'])) {
                if (! is_int($spec['scheduled_days']) || $spec['scheduled_days'] < 0 || $spec['scheduled_days'] > 3650) {
                    throw new ValidationException(['effects' => ["Effect {$index} has an invalid scheduled_days."]], 'Invalid effect declaration.');
                }
            }

            if (isset($spec['outbox_event_type'])
                && (! is_string($spec['outbox_event_type']) || preg_match('/^[a-z][a-z0-9_.-]{2,127}$/', $spec['outbox_event_type']) !== 1)) {
                throw new ValidationException(['effects' => ["Effect {$index} has an invalid outbox_event_type."]], 'Invalid effect declaration.');
            }

            if (isset($spec['outcome_effect_type'])) {
                $outcomeType = $spec['outcome_effect_type'];

                if (! is_string($outcomeType) || $outcomeType === '') {
                    throw new ValidationException(['effects' => ["Effect {$index} has an invalid outcome_effect_type."]], 'Invalid effect declaration.');
                }

                $this->registry->resolve($outcomeType);
            }

            if (isset($spec['reverse_effect_type'])) {
                $reverseType = $spec['reverse_effect_type'];

                if (! is_string($reverseType) || $reverseType === '') {
                    throw new ValidationException(['effects' => ["Effect {$index} has an invalid reverse_effect_type."]], 'Invalid effect declaration.');
                }

                // Fail before the Decision is written when a template declares
                // a reversal that no module can execute.
                $this->registry->resolve($reverseType);
            }

            if (isset($spec['aggregate_id_field'])
                && ! in_array($spec['aggregate_id_field'], ['case_id', 'subject_id', 'requester_user_id', 'template_version'], true)) {
                throw new ValidationException(['effects' => ["Effect {$index} has an unsupported aggregate_id_field."]], 'Invalid effect declaration.');
            }
        }
    }

    /**
     * Persist declared effects for a completed approval. LOCAL effects due now
     * are executed in the caller's decision transaction; EXTERNAL effects stay
     * SCHEDULED and emit an ID-only outbox event; future/SCHEDULED effects wait
     * for effects:run-due.
     *
     * @param  array<int, mixed>  $effects
     * @return list<Effect>
     */
    public function materialize(CaseModel $case, Decision $decision, array $effects): array
    {
        $created = [];
        $now = $this->clock->now();

        foreach ($effects as $spec) {
            $spec = (array) $spec;
            $execution = (string) ($spec['execution'] ?? 'LOCAL');
            $aggregateType = isset($spec['aggregate_type']) && is_string($spec['aggregate_type']) && $spec['aggregate_type'] !== ''
                ? $spec['aggregate_type']
                : 'case';
            $aggregateId = $this->aggregateId($case, $spec);
            $scheduledFor = $this->scheduledFor($spec, $now);
            $type = (string) $spec['type'];

            $existingMax = Effect::query()
                ->where('effect_type', $type)
                ->where('aggregate_id', $aggregateId)
                ->max('effect_seq');
            $effectSeq = ((int) $existingMax) + 1;

            $needsScheduling = $execution !== 'LOCAL'
                || ($scheduledFor !== null && $scheduledFor > $now->toDateString());

            $effect = Effect::query()->create([
                'case_id' => $case->id,
                'decision_id' => $decision->id,
                'effect_type' => $type,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'effect_seq' => $effectSeq,
                'status' => EffectStatus::SCHEDULED,
                'scheduled_for' => $scheduledFor,
                'applied_at' => null,
                'failed_at' => null,
                'failure_reason' => null,
                'attempts' => 0,
                'reversal_of_id' => null,
                'payload_json' => [
                    'case_id' => $case->id,
                    'decision_id' => $decision->id,
                    'template_version' => $case->template_version,
                    'case_payload' => $case->payload_json,
                    'reverse_effect_type' => isset($spec['reverse_effect_type']) && is_string($spec['reverse_effect_type'])
                        ? $spec['reverse_effect_type']
                        : null,
                ],
                'requires_escalation' => false,
            ]);

            CaseEvent::query()->create([
                'case_id' => $case->id,
                'event_type' => 'effect.created',
                'actor_user_id' => null,
                'payload_json' => [
                    'case_id' => $case->id,
                    'effect_id' => $effect->id,
                    'effect_type' => $type,
                    'execution' => $execution,
                ],
                'occurred_at' => $now,
            ]);

            $created[] = $effect;

            if ($execution === 'EXTERNAL') {
                $this->recordExternalOutbox($effect, $spec);
            } elseif ($needsScheduling) {
                // Future/SCHEDULED effects wait for the idempotent due runner.
            } else {
                $this->executor->apply($effect->id);
            }
        }

        $case->refresh();
        $this->refreshCaseEffectStatus($case);

        return array_map(static fn (Effect $effect): Effect => $effect->fresh() ?? $effect, $created);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    /**
     * Materialize and execute the template's declared outcome effect for a
     * rejected, returned or withdrawn case. Outcome effects are not approval
     * effects: they release holds and settle a pending domain projection.
     */
    public function materializeOutcome(CaseModel $case, ?Decision $decision = null): ?Effect
    {
        $snapshot = (array) ($case->template_snapshot_json ?? []);
        $outcomeType = null;

        foreach ((array) ($snapshot['effects'] ?? []) as $spec) {
            if (is_array($spec) && is_string($spec['outcome_effect_type'] ?? null) && $spec['outcome_effect_type'] !== '') {
                $outcomeType = (string) $spec['outcome_effect_type'];
                break;
            }
        }

        if ($outcomeType === null) {
            return null;
        }

        $this->registry->resolve($outcomeType);
        $existing = Effect::query()
            ->where('case_id', $case->id)
            ->where('effect_type', $outcomeType)
            ->whereNull('reversal_of_id')
            ->first();

        if ($existing instanceof Effect) {
            return $existing;
        }

        $aggregateId = (string) $case->id;
        $effectSeq = ((int) Effect::query()
            ->where('effect_type', $outcomeType)
            ->where('aggregate_id', $aggregateId)
            ->max('effect_seq')) + 1;
        $now = $this->clock->now();

        /** @var Effect $effect */
        $effect = Effect::query()->create([
            'case_id' => $case->id,
            'decision_id' => $decision?->id,
            'effect_type' => $outcomeType,
            'aggregate_type' => 'case',
            'aggregate_id' => $aggregateId,
            'effect_seq' => $effectSeq,
            'status' => EffectStatus::SCHEDULED,
            'scheduled_for' => null,
            'applied_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
            'attempts' => 0,
            'reversal_of_id' => null,
            'payload_json' => [
                'case_id' => $case->id,
                'decision_id' => $decision?->id,
                'template_version' => $case->template_version,
                'case_payload' => $case->payload_json,
                'reverse_effect_type' => null,
            ],
            'requires_escalation' => false,
        ]);

        CaseEvent::query()->create([
            'case_id' => $case->id,
            'event_type' => 'effect.created',
            'actor_user_id' => null,
            'payload_json' => [
                'case_id' => $case->id,
                'effect_id' => $effect->id,
                'effect_type' => $outcomeType,
                'execution' => 'LOCAL',
            ],
            'occurred_at' => $now,
        ]);

        $result = $this->executor->apply((int) $effect->id);
        $this->refreshCaseEffectStatus($case->refresh());
        $effect->refresh();

        if ($result->status !== EffectStatus::APPLIED) {
            throw new ConflictException('The Workflow outcome effect failed and remains retryable.', [
                'reason' => 'OUTCOME_EFFECT_FAILED',
                'effect_id' => (int) $effect->id,
                'effect_type' => $outcomeType,
                'effect_status' => $result->status->value,
            ]);
        }

        return $effect;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function aggregateId(CaseModel $case, array $spec): string
    {
        $field = $spec['aggregate_id_field'] ?? null;

        if (is_string($field)) {
            return (string) match ($field) {
                'case_id' => $case->id,
                'subject_id' => $case->subject_id ?? '',
                'requester_user_id' => $case->requester_user_id,
                'template_version' => $case->template_version,
                // Validation rejects other values before materialisation.
                default => throw new \UnhandledMatchError("Unsupported aggregate_id_field: {$field}"),
            };
        }

        if (isset($spec['aggregate_id']) && (is_string($spec['aggregate_id']) || is_int($spec['aggregate_id']))) {
            return (string) $spec['aggregate_id'];
        }

        return (string) $case->id;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function scheduledFor(array $spec, CarbonImmutable $now): ?string
    {
        if (isset($spec['scheduled_days']) && is_int($spec['scheduled_days'])) {
            return $now->toDateString() === $now->addDays($spec['scheduled_days'])->toDateString()
                ? $now->addDays($spec['scheduled_days'])->toDateString()
                : $now->addDays($spec['scheduled_days'])->toDateString();
        }

        if (isset($spec['scheduled_for']) && is_string($spec['scheduled_for'])) {
            return $spec['scheduled_for'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function recordExternalOutbox(Effect $effect, array $spec): void
    {
        $eventType = isset($spec['outbox_event_type']) && is_string($spec['outbox_event_type'])
            ? $spec['outbox_event_type']
            : 'workflow.effect.external_requested.v1';

        // IDs and versions only. Never copy effect.payload_json or case payload
        // into an integration event.
        $this->outbox->record(
            eventType: $eventType,
            aggregateType: $effect->aggregate_type,
            aggregateId: $effect->aggregate_id,
            aggregateVersion: $effect->effect_seq,
            payload: [
                'case_id' => $effect->case_id,
                'decision_id' => $effect->decision_id,
                'effect_id' => $effect->id,
                'effect_type' => $effect->effect_type,
                'effect_seq' => $effect->effect_seq,
                'template_version' => $effect->payload_json['template_version'] ?? null,
            ],
        );
    }

    private function refreshCaseEffectStatus(CaseModel $case): void
    {
        $statuses = Effect::query()
            ->where('case_id', $case->id)
            ->pluck('status')
            ->map(static fn (mixed $status): string => $status instanceof EffectStatus ? $status->value : (string) $status)
            ->all();

        if ($statuses === []) {
            return;
        }

        $total = count($statuses);
        $countOf = static fn (EffectStatus $status): int => count(array_filter(
            $statuses,
            static fn (string $candidate): bool => $candidate === $status->value,
        ));

        $effectStatus = match (true) {
            in_array(EffectStatus::FAILED->value, $statuses, true) => CaseEffectStatus::FAILED,
            $countOf(EffectStatus::APPLIED) === $total => CaseEffectStatus::APPLIED,
            $countOf(EffectStatus::REVERSED) === $total => CaseEffectStatus::REVERSED,
            $countOf(EffectStatus::SCHEDULED) === $total => CaseEffectStatus::SCHEDULED,
            default => CaseEffectStatus::PARTIAL,
        };

        $case->forceFill(['effect_status' => $effectStatus])->save();
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
