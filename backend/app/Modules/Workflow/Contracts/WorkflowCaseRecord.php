<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Company-scoped case snapshot. It intentionally carries IDs and states rather
 * than a Workflow model so callers cannot mutate case internals.
 */
final readonly class WorkflowCaseRecord
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $id,
        public string $caseNo,
        public string $type,
        public string $subjectType,
        public ?int $subjectId,
        public string $title,
        public array $payload,
        public string $applicationStatus,
        public string $effectStatus,
        public string $businessDate,
    ) {}
}
