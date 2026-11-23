<?php

declare(strict_types=1);

namespace App\Modules\Talent\Contracts;

interface OfferIntegrityReader
{
    /**
     * @return list<array{tenant_id:int, candidate_id:int, accepted_count:int}>
     */
    public function candidatesWithMultipleAcceptedOffers(?int $tenantId = null): array;
}
