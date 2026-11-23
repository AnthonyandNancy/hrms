<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Contracts;

/**
 * Lifecycle-owned conversion port used by Talent after an offer acceptance.
 * Implementations must be idempotent by offer_id: a replay returns the same
 * onboarding case and never creates a second one.
 */
interface TalentOnboardingDraftPort
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{case_id:int,replayed:bool}
     */
    public function createDraftFromAcceptedOffer(array $input): array;
}
