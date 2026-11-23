<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Contracts;

/**
 * Reporting port owned by Lifecycle. Lifecycle reporting tables are not on
 * this branch, so turnover is explicitly unavailable.
 */
interface TurnoverDirectory
{
    public function available(int $tenantId): bool;

    public function unavailableReason(int $tenantId): ?string;

    /**
     * @return list<array{employment_id: int, terminated_on: string, voluntary: bool}>
     */
    public function terminations(int $tenantId, string $asOf): array;
}
