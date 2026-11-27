<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Contracts;

/**
 * Reporting port owned by Lifecycle. Termination facts exist, but no
 * voluntary-turnover reporting projection is published yet, so turnover is
 * explicitly unavailable.
 */
interface TurnoverDirectory
{
    public function available(): bool;

    public function unavailableReason(): ?string;

    /**
     * @return list<array{employment_id: int, terminated_on: string, voluntary: bool}>
     */
    public function terminations(string $asOf): array;
}
