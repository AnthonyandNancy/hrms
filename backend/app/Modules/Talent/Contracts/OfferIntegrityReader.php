<?php

declare(strict_types=1);

namespace App\Modules\Talent\Contracts;

interface OfferIntegrityReader
{
    /**
     * @return list<array{candidate_id:int, accepted_count:int}>
     */
    public function candidatesWithMultipleAcceptedOffers(): array;
}
