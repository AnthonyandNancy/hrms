<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Domain;

use App\Shared\Time\BusinessDate;

/**
 * One authoritative mapping from a frozen intent payload to its planned
 * business effective date. The date is persisted on the intent at creation so
 * the ledger can filter and paginate without parsing arbitrary JSON.
 */
final class LifecycleEffectiveDate
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function forPayload(string $caseType, array $payload): ?string
    {
        $candidate = match (strtoupper($caseType)) {
            LifecycleCaseType::ONBOARDING->value,
            LifecycleCaseType::REHIRE->value => $payload['hire_date'] ?? null,
            LifecycleCaseType::OFFBOARD->value => $payload['last_working_date'] ?? null,
            default => $payload['effective_date'] ?? $payload['effective_from'] ?? null,
        };

        return is_string($candidate) && BusinessDate::tryFrom($candidate) !== null
            ? BusinessDate::from($candidate)->value()
            : null;
    }
}
