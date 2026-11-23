<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

final readonly class LeaveLiabilityRow
{
    public function __construct(
        public int $employmentId,
        public int $leaveTypeId,
        public string $leaveTypeCode,
        public string $leaveTypeName,
        public int $balanceMinutes,
    ) {}
}
