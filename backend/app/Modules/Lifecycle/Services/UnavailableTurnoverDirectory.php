<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Contracts\TurnoverDirectory;

final class UnavailableTurnoverDirectory implements TurnoverDirectory
{
    public function available(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Lifecycle stores termination and offboarding facts, but this reference binding does not yet publish a voluntary-turnover reporting projection; turnover is unavailable rather than inferred from employment status.';
    }

    /** @return list<array{employment_id: int, terminated_on: string, voluntary: bool}> */
    public function terminations(string $asOf): array
    {
        return [];
    }
}
