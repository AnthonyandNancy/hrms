<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\LeaveBalanceReader;

final class LeaveBalanceReaderService implements LeaveBalanceReader
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    public function outstanding(int $tenantId, int $employmentId, string $asOf): array
    {
        return $this->balances->outstanding($tenantId, $employmentId, $asOf);
    }
}
