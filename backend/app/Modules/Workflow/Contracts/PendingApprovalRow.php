<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

final readonly class PendingApprovalRow
{
    public function __construct(
        public int $caseId,
        public string $caseNo,
        public string $title,
        public ?int $employmentId,
        public string $businessDate,
        public string $taskStatus,
        public ?string $nodeKey,
        public ?string $dueAt,
    ) {}
}
