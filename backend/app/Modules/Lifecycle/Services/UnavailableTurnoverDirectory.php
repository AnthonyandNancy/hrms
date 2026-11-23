<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Contracts\TurnoverDirectory;

final class UnavailableTurnoverDirectory implements TurnoverDirectory
{
    public function available(int $tenantId): bool
    {
        return false;
    }

    public function unavailableReason(int $tenantId): ?string
    {
        $reason = $tenantId <= 0 ? null : 'Lifecycle termination projections are not present on this branch; turnover is unavailable.';

        return $reason;
    }

    /** @return list<array{employment_id: int, terminated_on: string, voluntary: bool}> */
    public function terminations(int $tenantId, string $asOf): array
    {
        return [];
    }
}
