<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

final readonly class DomainEffectCommand
{
    /**
     * @param  array<string, mixed>  $payload  Persisted effect payload, including the frozen case payload.
     */
    public function __construct(
        public int $tenantId,
        public int $effectId,
        public int $caseId,
        public ?int $decisionId,
        public string $effectType,
        public string $aggregateType,
        public string $aggregateId,
        public int $effectSeq,
        public array $payload,
    ) {}
}
