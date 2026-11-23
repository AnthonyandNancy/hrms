<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Transport-neutral handler result. Status is one of APPLIED, SCHEDULED or
 * REVERSED; failures are exceptions so EffectExecutor owns compensation.
 */
final readonly class DomainEffectResult
{
    public const APPLIED = 'APPLIED';

    public const SCHEDULED = 'SCHEDULED';

    public const REVERSED = 'REVERSED';

    /**
     * @param  list<array{event_type: string, payload: array<string, mixed>, scheduled_for?: string|null}>  $outboxEvents
     */
    public function __construct(
        public string $status = self::APPLIED,
        public array $outboxEvents = [],
    ) {}

    public static function applied(): self
    {
        return new self;
    }

    public static function reversed(): self
    {
        return new self(self::REVERSED);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function appliedWithOutbox(string $eventType, array $payload): self
    {
        return new self(self::APPLIED, [['event_type' => $eventType, 'payload' => $payload]]);
    }
}
