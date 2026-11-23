<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\LeaveRequestReader;

final class LeaveRequestReaderService implements LeaveRequestReader
{
    public function __construct(private readonly LeaveRequestService $requests) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forEmployment(int $tenantId, int $employmentId, ?string $asOf = null): array
    {
        return $this->requests->forEmployment($tenantId, $employmentId, $asOf);
    }
}
