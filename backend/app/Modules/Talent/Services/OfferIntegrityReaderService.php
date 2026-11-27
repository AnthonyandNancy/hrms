<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Contracts\OfferIntegrityReader;
use Illuminate\Support\Facades\DB;

final class OfferIntegrityReaderService implements OfferIntegrityReader
{
    public function candidatesWithMultipleAcceptedOffers(): array
    {
        return DB::table('offers')
            ->where('status', 'ACCEPTED')
            ->groupBy('candidate_id')
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw('candidate_id, COUNT(*) as accepted_count')
            ->get()
            ->map(static fn (object $row): array => [
                'candidate_id' => (int) $row->candidate_id,
                'accepted_count' => (int) $row->accepted_count,
            ])->all();
    }
}
