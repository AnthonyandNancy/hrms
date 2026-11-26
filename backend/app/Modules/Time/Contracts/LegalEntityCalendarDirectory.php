<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * Company calendar port. The single-company schema has exactly one calendar
 * row, so the contract carries no tenant or legal-entity identifiers. The
 * historical name is retained to avoid a cross-module rename ripple.
 */
interface LegalEntityCalendarDirectory
{
    public function timezone(): ?string;

    public function standardDayMinutes(): int;

    /**
     * @return list<int>
     */
    public function workweek(): array;
}
