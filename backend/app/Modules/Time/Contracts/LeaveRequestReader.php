<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * Read port for a person's own leave requests. Consumers never read Time
 * tables directly.
 */
interface LeaveRequestReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forEmployment(int $employmentId, ?string $asOf = null): array;
}
