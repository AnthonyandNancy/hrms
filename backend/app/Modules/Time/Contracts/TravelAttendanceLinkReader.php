<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

use App\Modules\Time\Services\TravelAttendanceLinkReaderService;
use Illuminate\Container\Attributes\Bind;

/**
 * Narrow read port over approved-trip attendance links. Consumers receive
 * calendar facts only; they must not infer punches or worked minutes from a
 * business trip.
 */
#[Bind(TravelAttendanceLinkReaderService::class)]
interface TravelAttendanceLinkReader
{
    /**
     * @return list<array{travel_id: int, employment_id: int, from_date: string, to_date: string, source_case_id: int, status: string}>
     */
    public function forEmployment(int $employmentId, string $fromDate, string $toDate): array;
}
