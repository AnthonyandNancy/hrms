<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Contracts\OfferIntegrityReader;
use Illuminate\Support\Facades\DB;

final class OfferIntegrityReaderService implements OfferIntegrityReader
{
    public function candidatesWithMultipleAcceptedOffers(?int $tenantId = null): array
    {
        return DB::table('offers')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('status', 'ACCEPTED')
            ->groupBy('tenant_id', 'candidate_id')
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw('tenant_id, candidate_id, COUNT(*) as accepted_count')
            ->get()
            ->map(static fn (object $row): array => [
                'tenant_id' => (int) $row->tenant_id,
                'candidate_id' => (int) $row->candidate_id,
                'accepted_count' => (int) $row->accepted_count,
            ])->all();
    }
}
