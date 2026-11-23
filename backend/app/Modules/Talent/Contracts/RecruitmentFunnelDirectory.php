<?php

declare(strict_types=1);

namespace App\Modules\Talent\Contracts;

/**
 * Reporting port owned by Talent. The Talent vertical is not implemented on
 * this branch; the reference adapter returns an explicit unavailable status.
 */
interface RecruitmentFunnelDirectory
{
    public function available(int $tenantId): bool;

    public function unavailableReason(int $tenantId): ?string;

    /**
     * @return list<RecruitmentFunnelRow>
     */
    public function funnel(int $tenantId, string $asOf): array;
}
