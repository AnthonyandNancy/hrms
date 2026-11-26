<?php

declare(strict_types=1);

namespace App\Modules\Talent\Contracts;

/**
 * Reporting port owned by Talent. Requisitions and candidates exist, but no
 * Reporting funnel projection is published yet, so the reference adapter
 * returns an explicit unavailable status.
 */
interface RecruitmentFunnelDirectory
{
    public function available(): bool;

    public function unavailableReason(): string;

    /**
     * @return list<RecruitmentFunnelRow>
     */
    public function funnel(string $asOf): array;
}
