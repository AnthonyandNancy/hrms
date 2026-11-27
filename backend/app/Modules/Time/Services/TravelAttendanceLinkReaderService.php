<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\TravelAttendanceLinkReader;
use App\Modules\Time\Models\TravelAttendanceLink;

/**
 * Read-only projection of travel attendance links. It deliberately exposes no
 * mutation method: TravelService owns ACTIVE/RETIRED transitions.
 */
final class TravelAttendanceLinkReaderService implements TravelAttendanceLinkReader
{
    /**
     * @return list<array{travel_id: int, employment_id: int, from_date: string, to_date: string, source_case_id: int, status: string}>
     */
    public function forEmployment(int $employmentId, string $fromDate, string $toDate): array
    {
        return TravelAttendanceLink::query()
            ->where('employment_id', $employmentId)
            ->where('status', TravelAttendanceLink::STATUS_ACTIVE)
            ->where('from_date', '<=', $toDate)
            ->where('to_date', '>=', $fromDate)
            ->orderBy('from_date')
            ->orderBy('travel_id')
            ->get()
            ->map(static fn (TravelAttendanceLink $link): array => [
                'travel_id' => (int) $link->travel_id,
                'employment_id' => (int) $link->employment_id,
                'from_date' => $link->from_date->format('Y-m-d'),
                'to_date' => $link->to_date->format('Y-m-d'),
                'source_case_id' => (int) $link->source_case_id,
                'status' => (string) $link->status,
            ])
            ->all();
    }
}
