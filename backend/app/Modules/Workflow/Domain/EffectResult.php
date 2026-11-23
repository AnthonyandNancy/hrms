<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain;

use App\Modules\Workflow\Enums\EffectStatus;

/**
 * Successful handler outcome. A handler may report SCHEDULED (deferred local
 * execution) or APPLIED; failures are represented by exceptions so the
 * executor owns failure recording and retry policy.
 */
final readonly class EffectResult
{
    /**
     * @param  list<array{event_type: string, payload: array<string, mixed>, scheduled_for?: string|null}>  $outboxEvents
     */
    public function __construct(
        public EffectStatus $status = EffectStatus::APPLIED,
        public array $outboxEvents = [],
    ) {}

    public static function applied(): self
    {
        return new self(EffectStatus::APPLIED);
    }

    public static function scheduled(): self
    {
        return new self(EffectStatus::SCHEDULED);
    }

    public static function reversed(): self
    {
        return new self(EffectStatus::REVERSED);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function appliedWithOutbox(string $eventType, array $payload): self
    {
        return new self(EffectStatus::APPLIED, [
            ['event_type' => $eventType, 'payload' => $payload],
        ]);
    }
}
