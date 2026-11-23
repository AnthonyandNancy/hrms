<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

final readonly class WorkflowCaseSnapshot
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $requesterUserId,
        public string $type,
        public string $subjectType,
        public ?int $subjectId,
        public string $status,
        public string $effectStatus,
        public array $payload,
        public string $businessDate,
        public string $caseNo = '',
        public string $title = '',
        public ?string $currentNode = null,
        public ?string $dueAt = null,
    ) {}

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }
}
