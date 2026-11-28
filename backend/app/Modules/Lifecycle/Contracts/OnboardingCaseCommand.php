<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Contracts;

/**
 * Canonical People -> Lifecycle onboarding entry.
 *
 * The caller supplies the already-normalised Lifecycle onboarding payload.
 * The implementation submits a Workflow Case and must never create Person,
 * Employment, Assignment or EmploymentTerms rows; those remain the exclusive
 * responsibility of the approved Onboarding Effect.
 */
interface OnboardingCaseCommand
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     case_id:int,
     *     case_no:string,
     *     case_type:string,
     *     application_status:string,
     *     effect_status:string,
     *     canonical_endpoint:string,
     *     replayed:bool
     * }
     */
    public function submit(int $actorUserId, array $payload, string $idempotencyKey, string $businessDate): array;
}
