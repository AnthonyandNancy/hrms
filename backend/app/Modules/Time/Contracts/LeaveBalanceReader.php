<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

interface LeaveBalanceReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function outstanding(int $employmentId, string $asOf): array;
}
