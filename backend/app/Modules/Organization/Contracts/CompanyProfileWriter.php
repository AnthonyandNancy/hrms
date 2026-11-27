<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Single-company profile write port. Organization owns `company_profile`; no
 * other module may update it directly.
 */
interface CompanyProfileWriter
{
    /**
     * @param  array{name: string, country_code: string, timezone: string, currency_code: string}  $input
     * @return array{name: string, country_code: string, timezone: string, currency_code: string, calendar_policy: array<string, mixed>|null}
     */
    public function saveProfile(array $input, int $actorUserId, string $idempotencyKey): array;
}
