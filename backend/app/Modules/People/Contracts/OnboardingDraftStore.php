<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * People-owned encrypted draft for pending onboarding PII.
 *
 * Callers must never put the person payload itself into Workflow Case or
 * Intent JSON; they pass only the returned draft id.
 */
interface OnboardingDraftStore
{
    /**
     * Reserve or reuse the pending draft for this idempotency key/employee.
     *
     * @param  array<string, mixed>  $person
     * @return array{
     *     draft_id: int,
     *     employee_no: string,
     *     case_id: int|null,
     *     idempotency_key: string,
     *     status: string,
     *     replayed: bool
     * }
     */
    public function reserve(string $employeeNo, string $idempotencyKey, array $person, int $actorUserId): array;

    public function attachCase(int $draftId, int $caseId): void;

    /**
     * Decrypt the People-owned PII payload for the approved effect.
     *
     * @return array<string, mixed>
     */
    public function personFor(int $draftId): array;

    public function markConsumed(int $draftId): void;
}
