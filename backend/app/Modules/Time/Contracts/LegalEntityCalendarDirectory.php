<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

interface LegalEntityCalendarDirectory
{
    public function timezone(int $tenantId, int $legalEntityId): ?string;

    public function standardDayMinutes(int $tenantId, int $legalEntityId): int;

    /**
     * @return list<int>
     */
    public function workweek(int $tenantId, int $legalEntityId): array;
}
